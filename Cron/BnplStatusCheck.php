<?php
namespace Paypercut\Payment\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Paypercut\Payment\Model\Api\Client as PaypercutClient;
use Psr\Log\LoggerInterface;

/**
 * Cron job to check BNPL purchase status for pending orders.
 *
 * Since BNPL doesn't use the same webhook/IPN mechanism as card payments,
 * we poll the status endpoint to update orders.
 */
class BnplStatusCheck
{
    /** BNPL attempt statuses */
    const STATUS_CREATED = 'ATTEMPT_STATUS_CREATED';
    const STATUS_INITIALIZED = 'ATTEMPT_STATUS_INITIALIZED';
    const STATUS_IN_PROGRESS = 'ATTEMPT_STATUS_IN_PROGRESS';
    const STATUS_COMPLETED = 'ATTEMPT_STATUS_COMPLETED';
    const STATUS_ERRORED = 'ATTEMPT_STATUS_ERRORED';
    const STATUS_DECLINED = 'ATTEMPT_STATUS_DECLINED';

    /** Maximum age of orders to check (hours) */
    const MAX_ORDER_AGE_HOURS = 48;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var PaypercutClient
     */
    private $apiClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param PaypercutClient $apiClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderCollectionFactory $orderCollectionFactory,
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        PaypercutClient $apiClient,
        LoggerInterface $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
    }

    /**
     * Execute cron job: check BNPL status for pending orders
     *
     * @return void
     */
    public function execute(): void
    {
        $orders = $this->getPendingBnplOrders();

        if ($orders->getSize() === 0) {
            return;
        }

        $this->logger->info('Paypercut: BNPL status check started', [
            'pending_orders' => $orders->getSize()
        ]);

        foreach ($orders as $order) {
            $this->checkOrderStatus($order);
        }

        $this->logger->info('Paypercut: BNPL status check completed');
    }

    /**
     * Check and update status for a single BNPL order
     *
     * @param Order $order
     * @return void
     */
    public function checkOrderStatus(Order $order): void
    {
        $payment = $order->getPayment();
        $attemptId = $payment->getAdditionalInformation('paypercut_id');

        if (!$attemptId) {
            $this->logger->warning('Paypercut: BNPL order missing attempt_id', [
                'order_id' => $order->getIncrementId()
            ]);
            return;
        }

        // Skip if already processed
        if ($payment->getAdditionalInformation('bnpl_status_checked') === 'completed') {
            return;
        }

        try {
            $response = $this->apiClient->getBnplAttemptStatus($attemptId);
            $attemptData = $response['attempt'] ?? [];
            $status = $attemptData['status'] ?? '';

            $this->logger->info('Paypercut: BNPL status response', [
                'order_id' => $order->getIncrementId(),
                'attempt_id' => $attemptId,
                'status' => $status,
                'status_reason' => $attemptData['status_reason'] ?? '',
                'provider_name' => $attemptData['provider_name'] ?? ''
            ]);

            // Save BNPL response data to payment
            $this->saveBnplResponseData($payment, $attemptData);

            switch ($status) {
                case self::STATUS_COMPLETED:
                    $this->processApproved($order, $attemptData);
                    break;

                case self::STATUS_DECLINED:
                case self::STATUS_ERRORED:
                    $this->processDeclined($order, $status, $attemptData);
                    break;

                case self::STATUS_CREATED:
                case self::STATUS_INITIALIZED:
                case self::STATUS_IN_PROGRESS:
                default:
                    // Still pending, will check again on next cron run
                    // Save the response data so it's visible in admin
                    $this->orderRepository->save($order);
                    $this->logger->info('Paypercut: BNPL order still pending', [
                        'order_id' => $order->getIncrementId(),
                        'status' => $status
                    ]);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->error('Paypercut: BNPL status check failed', [
                'order_id' => $order->getIncrementId(),
                'attempt_id' => $attemptId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process an approved BNPL purchase
     *
     * @param Order $order
     * @param array $attemptData
     * @return void
     */
    private function processApproved(Order $order, array $attemptData): void
    {
        $payment = $order->getPayment();
        $attemptId = $attemptData['attempt_id'] ?? '';
        $purchaseId = $attemptData['purchase_id'] ?? '';

        // Use purchase_id as transaction ID, fallback to attempt_id
        $transactionId = $purchaseId ?: $attemptId;
        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(false);

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

        $providerName = $attemptData['provider_name'] ?? 'BNPL';
        $order->addCommentToStatusHistory(
            __('BNPL payment approved via %1. Purchase ID: %2, Attempt ID: %3',
                $providerName,
                $purchaseId,
                $attemptId
            )
        );

        $payment->setAdditionalInformation('bnpl_status_checked', 'completed');
        $this->orderRepository->save($order);

        $this->logger->info('Paypercut: BNPL order approved and invoiced', [
            'order_id' => $order->getIncrementId(),
            'purchase_id' => $purchaseId,
            'provider' => $providerName
        ]);
    }

    /**
     * Process a declined/expired/canceled BNPL purchase
     *
     * @param Order $order
     * @param string $status
     * @param array $attemptData
     * @return void
     */
    private function processDeclined(Order $order, string $status, array $attemptData): void
    {
        $payment = $order->getPayment();
        $statusReason = $attemptData['status_reason'] ?? '';
        $providerName = $attemptData['provider_name'] ?? 'BNPL';

        $statusLabel = str_replace('ATTEMPT_STATUS_', '', $status);

        $order->cancel();
        $order->addCommentToStatusHistory(
            __('BNPL payment %1 via %2. Reason: %3',
                strtolower($statusLabel),
                $providerName,
                $statusReason ?: __('No reason provided')
            )
        );

        $payment->setAdditionalInformation('bnpl_status_checked', 'completed');
        $this->orderRepository->save($order);

        $this->logger->info('Paypercut: BNPL order canceled', [
            'order_id' => $order->getIncrementId(),
            'status' => $status,
            'reason' => $statusReason
        ]);
    }

    /**
     * Save BNPL response data to payment additional information
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param array $attemptData
     * @return void
     */
    private function saveBnplResponseData($payment, array $attemptData): void
    {
        if (!empty($attemptData['purchase_id'])) {
            $payment->setAdditionalInformation('bnpl_purchase_id', $attemptData['purchase_id']);
        }
        if (!empty($attemptData['provider_name'])) {
            $payment->setAdditionalInformation('bnpl_provider_name', $attemptData['provider_name']);
        }
        if (!empty($attemptData['provider_purchase_ref'])) {
            $payment->setAdditionalInformation('bnpl_provider_purchase_ref', $attemptData['provider_purchase_ref']);
        }
        if (!empty($attemptData['status'])) {
            $payment->setAdditionalInformation('bnpl_status', $attemptData['status']);
        }
        if (!empty($attemptData['status_reason'])) {
            $payment->setAdditionalInformation('bnpl_status_reason', $attemptData['status_reason']);
        }
        if (!empty($attemptData['merchant_purchase_ref'])) {
            $payment->setAdditionalInformation('bnpl_merchant_purchase_ref', $attemptData['merchant_purchase_ref']);
        }
    }

    /**
     * Get pending BNPL orders that need status checking
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private function getPendingBnplOrders()
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . self::MAX_ORDER_AGE_HOURS . ' hours'));

        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = payment.parent_id',
            []
        )->where(
            'payment.method = ?',
            'paypercut_bnpl'
        )->where(
            'main_table.state IN (?)',
            [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW]
        )->where(
            'main_table.created_at >= ?',
            $cutoffDate
        )->where(
            'payment.additional_information NOT LIKE ?',
            '%"bnpl_status_checked":"completed"%'
        );

        return $collection;
    }
}
