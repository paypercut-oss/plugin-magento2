<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Paypercut\Payment\Model\Subscription\SubscriptionManager;
use Paypercut\Payment\Model\Api\Client as PaypercutClient;
use Paypercut\Payment\Model\PaypercutOrderHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Paypercut\Payment\Model\Adminhtml\Source\RefundAction;
use Psr\Log\LoggerInterface;

/**
 * Class Ipn
 * Handles IPN (Instant Payment Notification) callbacks from Paypercut
 */
class Ipn implements HttpPostActionInterface, CsrfAwareActionInterface
{
    const CONFIG_PATH_WEBHOOK_SECRET = 'payment/paypercut_card/webhook_secret';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepository;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var CreditmemoService
     */
    private $creditmemoService;

    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var SubscriptionManager
     */
    private $subscriptionManager;

    /**
     * @var PaypercutClient
     */
    private $apiClient;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var PaypercutOrderHelper
     */
    private $orderHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param TransactionRepositoryInterface $transactionRepository
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param CreditmemoService $creditmemoService
     * @param CreditmemoFactory $creditmemoFactory
     * @param SubscriptionManager $subscriptionManager
     * @param PaypercutClient $apiClient
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param PaypercutOrderHelper $orderHelper
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderRepositoryInterface $orderRepository,
        OrderCollectionFactory $orderCollectionFactory,
        TransactionRepositoryInterface $transactionRepository,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        CreditmemoService $creditmemoService,
        CreditmemoFactory $creditmemoFactory,
        SubscriptionManager $subscriptionManager,
        PaypercutClient $apiClient,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        PaypercutOrderHelper $orderHelper
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->orderRepository = $orderRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->transactionRepository = $transactionRepository;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceService = $invoiceService;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->subscriptionManager = $subscriptionManager;
        $this->apiClient = $apiClient;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->orderHelper = $orderHelper;
    }

    /**
     * Execute IPN handler
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $rawBody = $this->request->getContent();

            if (!$this->validateIpn($rawBody)) {
                throw new \Exception('Invalid IPN signature');
            }

            $webhookData = json_decode($rawBody, true);

            $this->logger->info('Paypercut IPN received', ['data' => $webhookData]);

            // Extract event type and data
            $eventType = $webhookData['type'] ?? null;
            $eventData = $webhookData['data']['object'] ?? null;

            if (!$eventType || !$eventData) {
                throw new \Exception('Missing event type or data');
            }

            $this->logger->info('Paypercut: Processing webhook event', [
                'event_type' => $eventType,
                'event_id' => $webhookData['id'] ?? null
            ]);

            // Process based on event type
            switch ($eventType) {
                case 'checkout_session.completed':
                    $this->processCheckoutCompleted($eventData);
                    break;
                    
                case 'payment_intent.captured':
                case 'payment_intent.succeeded':
                    $this->processPaymentSucceeded($eventData);
                    break;
                    
                case 'payment_intent.authorized':
                    $this->logger->info('Paypercut: Payment authorized (captured will follow)', [
                        'payment_intent_id' => $eventData['id'] ?? null
                    ]);
                    // Don't process yet - wait for captured event
                    break;
                    
                case 'payment_intent.payment_failed':
                    $this->processPaymentFailed($eventData);
                    break;
                
                case 'refund.created':
                case 'refund.succeeded':
                    $this->processRefundCreated($eventData);
                    break;
                    
                default:
                    $this->logger->info('Paypercut: Unhandled webhook event type', [
                        'event_type' => $eventType
                    ]);
                    break;
            }

            $result->setData([
                'success' => true,
                'message' => 'Webhook processed successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Paypercut IPN error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            $result->setHttpResponseCode(400);
            $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        return $result;
    }

    /**
     * Process checkout session completed event
     *
     * @param array $checkoutData
     * @return void
     */
    private function processCheckoutCompleted(array $checkoutData)
    {
        $checkoutId = $checkoutData['id'] ?? null;
        
        if (!$checkoutId) {
            $this->logger->error('Paypercut: Missing checkout ID in webhook');
            return;
        }

        // Find order by checkout ID (paypercut_id)
        $order = $this->getOrderByPaypercutId($checkoutId);
        
        if (!$order) {
            $this->logger->warning('Paypercut: Order not found for checkout', [
                'checkout_id' => $checkoutId
            ]);
            return;
        }

        $this->logger->info('Paypercut: Checkout session completed', [
            'order_id' => $order->getIncrementId(),
            'checkout_id' => $checkoutId,
            'payment_status' => $checkoutData['payment_status'] ?? null
        ]);

        // Checkout completed - payment processing will be handled by payment_intent.captured event
    }

    /**
     * Process payment succeeded event
     *
     * @param array $paymentIntentData
     * @return void
     */
    private function processPaymentSucceeded(array $paymentIntentData)
    {
        $paymentIntentId = $paymentIntentData['id'] ?? null;
        
        if (!$paymentIntentId) {
            $this->logger->error('Paypercut: Missing payment intent ID in webhook');
            return;
        }

        // Find order by payment intent ID
        $order = $this->getOrderByPaymentIntentId($paymentIntentId);
        
        if (!$order) {
            $this->logger->warning('Paypercut: Order not found for payment intent', [
                'payment_intent_id' => $paymentIntentId
            ]);
            return;
        }

        $payment = $order->getPayment();
        $transactionId = $paymentIntentId;
        
        $this->logger->info('Paypercut: Payment captured', [
            'order_id' => $order->getIncrementId(),
            'payment_intent_id' => $paymentIntentId,
            'amount' => $paymentIntentData['amount'] ?? null
        ]);

        // Set transaction ID
        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(false);
        
        // Extract and save payment method if available
        if (!empty($paymentIntentData['payment_method'])) {
            $payment->setAdditionalInformation('paypercut_payment_method_id', $paymentIntentData['payment_method']);
            
            $this->logger->info('Paypercut: Saved payment method ID from webhook', [
                'order_id' => $order->getIncrementId(),
                'payment_method_id' => $paymentIntentData['payment_method']
            ]);
        }
        
        // Extract and save payment/charge ID if available (for refunds)
        if (!empty($paymentIntentData['latest_charge'])) {
            $payment->setAdditionalInformation('paypercut_payment_id', $paymentIntentData['latest_charge']);
            
            $this->logger->info('Paypercut: Saved payment ID from webhook', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => $paymentIntentData['latest_charge']
            ]);
        } elseif (!empty($paymentIntentData['charges']) && is_array($paymentIntentData['charges']) && !empty($paymentIntentData['charges'][0]['id'])) {
            // Fallback to first charge ID if latest_charge not available
            $payment->setAdditionalInformation('paypercut_payment_id', $paymentIntentData['charges'][0]['id']);
            
            $this->logger->info('Paypercut: Saved payment ID from charges array', [
                'order_id' => $order->getIncrementId(),
                'payment_id' => $paymentIntentData['charges'][0]['id']
            ]);
        }

        // Create subscriptions if this order has subscription items
        $this->createSubscriptions($order);

        // Create invoice if possible
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();

            $transactionSave = $this->transactionFactory->create();
            $transactionSave->addObject($invoice)
                ->addObject($order)
                ->save();
        }

        $order->setState(Order::STATE_PROCESSING)
            ->setStatus(Order::STATE_PROCESSING);
        
        $order->addCommentToStatusHistory(
            __('Payment confirmed via Paypercut. Transaction ID: %1', $transactionId)
        );
        
        $this->orderRepository->save($order);
    }

    /**
     * Process payment failed event
     *
     * @param array $paymentIntentData
     * @return void
     */
    private function processPaymentFailed(array $paymentIntentData)
    {
        $paymentIntentId = $paymentIntentData['id'] ?? null;
        
        if (!$paymentIntentId) {
            return;
        }

        $order = $this->getOrderByPaymentIntentId($paymentIntentId);
        
        if (!$order) {
            return;
        }

        $this->logger->info('Paypercut: Payment failed', [
            'order_id' => $order->getIncrementId(),
            'payment_intent_id' => $paymentIntentId
        ]);

        $order->cancel();
        $order->addCommentToStatusHistory(__('Payment failed via Paypercut gateway.'));
        
        $this->orderRepository->save($order);
    }

    /**
     * Process refund created event
     *
     * @param array $refundData
     * @return void
     */
    private function processRefundCreated(array $refundData)
    {
        $refundId = $refundData['id'] ?? null;
        $paymentIntentId = $refundData['payment_intent'] ?? null;
        $refundAmount = $refundData['amount'] ?? null; // Amount in cents
        
        if (!$refundId || !$paymentIntentId) {
            $this->logger->error('Paypercut: Missing refund ID or payment intent ID in webhook');
            return;
        }

        // Find order by payment intent ID
        $order = $this->getOrderByPaymentIntentId($paymentIntentId);
        
        if (!$order) {
            $this->logger->warning('Paypercut: Order not found for refund', [
                'refund_id' => $refundId,
                'payment_intent_id' => $paymentIntentId
            ]);
            return;
        }

        // Check if refund action is set to "credit_memo_on_refund"
        $refundAction = $this->scopeConfig->getValue(
            'payment/paypercut_card/refund_action',
            ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );

        if ($refundAction !== RefundAction::ACTION_CREDIT_MEMO_ON_REFUND) {
            $this->logger->info('Paypercut: Refund action not set to credit_memo_on_refund, skipping', [
                'order_id' => $order->getIncrementId(),
                'refund_action' => $refundAction,
                'refund_id' => $refundId
            ]);
            return;
        }

        $this->logger->info('Paypercut: Processing refund webhook', [
            'order_id' => $order->getIncrementId(),
            'refund_id' => $refundId,
            'amount' => $refundAmount
        ]);

        try {
            // Check if order can be refunded
            if (!$order->canCreditmemo()) {
                $this->logger->warning('Paypercut: Order cannot be refunded', [
                    'order_id' => $order->getIncrementId(),
                    'order_state' => $order->getState(),
                    'order_status' => $order->getStatus()
                ]);
                return;
            }

            // Check if this refund was already processed
            $payment = $order->getPayment();
            $processedRefunds = $payment->getAdditionalInformation('paypercut_processed_refund_webhooks') ?: [];
            if (!is_array($processedRefunds)) {
                $processedRefunds = [];
            }

            if (in_array($refundId, $processedRefunds)) {
                $this->logger->info('Paypercut: Refund already processed', [
                    'order_id' => $order->getIncrementId(),
                    'refund_id' => $refundId
                ]);
                return;
            }

            // Calculate refund amount in order currency
            $refundAmountInOrderCurrency = $refundAmount / 100; // Convert from cents

            // Prepare credit memo data
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            
            // Set adjustment for partial refund if needed
            $orderGrandTotal = $order->getGrandTotal();
            $alreadyRefunded = $order->getTotalRefunded();
            $maxRefundable = $orderGrandTotal - $alreadyRefunded;

            if ($refundAmountInOrderCurrency > $maxRefundable) {
                $this->logger->warning('Paypercut: Refund amount exceeds refundable amount', [
                    'order_id' => $order->getIncrementId(),
                    'refund_amount' => $refundAmountInOrderCurrency,
                    'max_refundable' => $maxRefundable
                ]);
                $refundAmountInOrderCurrency = $maxRefundable;
            }

            // If refund amount doesn't match grand total, it's a partial refund
            $creditmemoGrandTotal = $creditmemo->getGrandTotal();
            if (abs($creditmemoGrandTotal - $refundAmountInOrderCurrency) > 0.01) {
                $adjustment = $refundAmountInOrderCurrency - $creditmemoGrandTotal;
                $creditmemo->setAdjustmentPositive($adjustment > 0 ? $adjustment : 0);
                $creditmemo->setAdjustmentNegative($adjustment < 0 ? abs($adjustment) : 0);
            }

            // Add comment to credit memo
            $creditmemo->addComment(
                __('Credit memo created automatically from Paypercut refund. Refund ID: %1', $refundId)
            );

            // Create the credit memo
            $this->creditmemoService->refund($creditmemo, false); // false = offline refund (already refunded in Paypercut)

            // Mark this refund as processed
            $processedRefunds[] = $refundId;
            $payment->setAdditionalInformation('paypercut_processed_refund_webhooks', $processedRefunds);
            $payment->save();

            $this->logger->info('Paypercut: Credit memo created successfully from refund', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId(),
                'refund_id' => $refundId,
                'amount' => $refundAmountInOrderCurrency
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Paypercut: Failed to create credit memo from refund', [
                'order_id' => $order->getIncrementId(),
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Create subscriptions for order if it has subscription items
     *
     * @param Order $order
     * @return void
     */
    private function createSubscriptions(Order $order)
    {
        // Check if subscriptions are enabled and order has subscription items
        if (!$this->subscriptionManager->isEnabled((int) $order->getStoreId())) {
            return;
        }

        if (!$this->subscriptionManager->orderHasSubscriptionItems($order)) {
            return;
        }

        try {
            $payment = $order->getPayment();
            
            // Get or create Paypercut customer
            $paypercutCustomerId = $this->orderHelper->getOrCreateCustomer($order);

            // Get payment method ID (optional)
            $paymentMethodId = $this->orderHelper->getPaymentMethodId($order, $paypercutCustomerId);

            if (!$paymentMethodId) {
                $this->logger->info('Paypercut: Creating subscription without payment method (will use invoice collection)', [
                    'order_id' => $order->getEntityId()
                ]);
            }

            // Create subscriptions
            $subscriptions = $this->subscriptionManager->createSubscriptionsFromOrder(
                $order,
                $paypercutCustomerId,
                $paymentMethodId
            );

            // Store subscription IDs in order
            if (!empty($subscriptions)) {
                $subscriptionIds = array_column($subscriptions, 'id');
                $payment->setAdditionalInformation('paypercut_subscription_ids', $subscriptionIds);
                
                $this->logger->info('Paypercut: Subscriptions created from IPN', [
                    'order_id' => $order->getEntityId(),
                    'subscription_ids' => $subscriptionIds
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Paypercut: Failed to create subscriptions from IPN', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get order by Paypercut checkout ID
     *
     * @param string $paypercutId
     * @return Order|null
     */
    private function getOrderByPaypercutId(string $paypercutId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = payment.parent_id',
            []
        )->where(
            'payment.additional_information LIKE ?',
            '%"paypercut_id":"' . $paypercutId . '"%'
        );
        
        /** @var Order $order */
        $order = $collection->getFirstItem();
        
        return $order->getId() ? $order : null;
    }

    /**
     * Get order by Paypercut payment intent ID
     *
     * @param string $paymentIntentId
     * @return Order|null
     */
    private function getOrderByPaymentIntentId(string $paymentIntentId): ?Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = payment.parent_id',
            []
        )->where(
            'payment.additional_information LIKE ?',
            '%"paypercut_payment_intent":"' . $paymentIntentId . '"%'
        );
        
        /** @var Order $order */
        $order = $collection->getFirstItem();
        
        return $order->getId() ? $order : null;
    }

    /**
     * Validate IPN signature using HMAC-SHA256
     *
     * Verifies the Paypercut-Signature header against the raw request body.
     * Header format: "t=<timestamp>,v1=<hex_signature>"
     * Signed payload: "<timestamp>.<raw_body>"
     *
     * @param string $rawBody
     * @return bool
     */
    private function validateIpn(string $rawBody): bool
    {
        $webhookSecret = $this->scopeConfig->getValue(
            self::CONFIG_PATH_WEBHOOK_SECRET,
            ScopeInterface::SCOPE_STORE
        );

        if (empty($webhookSecret)) {
            $this->logger->warning('Paypercut IPN: No webhook secret configured, skipping signature validation');
            return true;
        }

        $signatureHeader = $this->request->getHeader('Paypercut-Signature');
        if (!$signatureHeader) {
            $this->logger->error('Paypercut IPN: Missing Paypercut-Signature header');
            return false;
        }

        // Parse header: "t=<timestamp>,v1=<hex_signature>"
        $parts = explode(',', $signatureHeader);
        if (count($parts) < 2) {
            $this->logger->error('Paypercut IPN: Invalid signature header format');
            return false;
        }

        $timestampPart = explode('=', $parts[0], 2);
        $signaturePart = explode('=', $parts[1], 2);

        if (count($timestampPart) < 2 || count($signaturePart) < 2
            || $timestampPart[0] !== 't' || $signaturePart[0] !== 'v1'
        ) {
            $this->logger->error('Paypercut IPN: Invalid signature header components');
            return false;
        }

        $timestamp = (int) $timestampPart[1];
        $receivedSignature = $signaturePart[1];

        // Check timestamp tolerance (5 minutes)
        $timeDifference = abs(time() - $timestamp);
        if ($timeDifference > 300) {
            $this->logger->error('Paypercut IPN: Signature timestamp too old', [
                'timestamp' => $timestamp,
                'difference' => $timeDifference
            ]);
            return false;
        }

        // Compute expected signature: HMAC-SHA256 of "<timestamp>.<raw_body>"
        $signedPayload = $timestamp . '.' . $rawBody;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        // Constant-time comparison
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            $this->logger->error('Paypercut IPN: Signature mismatch');
            return false;
        }

        return true;
    }

    /**
     * Create CSRF validation exception
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for CSRF
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
