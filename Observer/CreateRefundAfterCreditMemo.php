<?php
declare(strict_types=1);

namespace Paypercut\Payment\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Store\Model\ScopeInterface;
use Paypercut\Payment\Model\Adminhtml\Source\RefundAction;
use Paypercut\Payment\Model\Api\Client;
use Paypercut\Payment\Model\Api\PaypercutApiException;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Paypercut\Payment\Model\Ui\ConfigProvider;
use Psr\Log\LoggerInterface;

/**
 * Observer to create refund in Paypercut when credit memo is created
 */
class CreateRefundAfterCreditMemo implements ObserverInterface
{
    /**
     * @var Client
     */
    private $apiClient;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventRecorder
     */
    private $recorder;

    /**
     * @param Client $apiClient
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param EventRecorder $recorder
     */
    public function __construct(
        Client $apiClient,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EventRecorder $recorder
    ) {
        $this->apiClient = $apiClient;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->recorder = $recorder;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var CreditmemoInterface $creditmemo */
        $creditmemo = $observer->getEvent()->getCreditmemo();

        if (!$creditmemo) {
            return;
        }

        $order = $creditmemo->getOrder();
        if (!$order) {
            return;
        }

        // Check if this is a Paypercut payment
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        // Check if refund action is set to "refund_on_credit_memo"
        $refundAction = $this->scopeConfig->getValue(
            'payment/paypercut_card/refund_action',
            ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );

        if ($refundAction !== RefundAction::ACTION_REFUND_ON_CREDIT_MEMO) {
            $this->logger->info('Paypercut: Refund action not set to refund_on_credit_memo, skipping', [
                'order_id' => $order->getIncrementId(),
                'refund_action' => $refundAction
            ]);
            return;
        }

        // Get payment intent ID from additional information or transaction ID
        $paymentIntentId = $payment->getAdditionalInformation('paypercut_payment_intent') 
            ?: $payment->getTransactionId();
        
        // Try to get payment ID (might be stored as latest_charge)
        $paymentId = $payment->getAdditionalInformation('paypercut_payment_id') 
            ?: $payment->getAdditionalInformation('paypercut_latest_charge')
            ?: null;

        if (!$paymentIntentId) {
            $this->logger->error('Paypercut: Missing payment intent ID for refund', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId()
            ]);
            $this->recorder->record(
                Event::failure('refund.rejected', 'missing_payment_intent', ['source' => 'credit_memo'])
                    ->about(['order_ref' => (string) $order->getIncrementId()])
            );
            return;
        }

        try {
            // Calculate refund amount in smallest currency unit (cents)
            $refundAmount = $creditmemo->getGrandTotal();
            $amountInCents = (int) round($refundAmount * 100);

            if ($amountInCents <= 0) {
                $this->recorder->record(
                    Event::failure('refund.rejected', 'invalid_amount', ['source' => 'credit_memo'])
                        ->about(['order_ref' => (string) $order->getIncrementId()])
                );
                return;
            }

            // Prepare refund data
            $refundData = [
                'amount' => $amountInCents,
                'payment' => $paymentId ?: $paymentIntentId, // Use payment ID if available, otherwise payment intent
                'payment_intent' => $paymentIntentId,
                'reason' => 'requested_by_customer'
            ];

            $this->logger->info('Paypercut: Creating refund for credit memo', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId(),
                'amount' => $refundAmount,
                'amount_cents' => $amountInCents
            ]);

            // Create refund via API
            $result = $this->apiClient->createRefund($refundData);

            if (!empty($result['id'])) {
                // Store refund ID in payment additional information
                $existingRefunds = $payment->getAdditionalInformation('paypercut_refund_ids') ?: [];
                if (!is_array($existingRefunds)) {
                    $existingRefunds = [];
                }
                $existingRefunds[] = $result['id'];
                $payment->setAdditionalInformation('paypercut_refund_ids', $existingRefunds);
                $payment->save();

                // Add comment to credit memo
                $creditmemo->addComment(
                    __('Paypercut refund created successfully. Refund ID: %1', $result['id'])
                );

                $this->logger->info('Paypercut: Refund created successfully', [
                    'order_id' => $order->getIncrementId(),
                    'creditmemo_id' => $creditmemo->getIncrementId(),
                    'refund_id' => $result['id']
                ]);

                // `has_reason` is a boolean on purpose: the reason text a
                // merchant types is theirs and never travels.
                $this->recorder->record(
                    Event::of('refund.succeeded', [
                        'source' => 'credit_memo',
                        'is_partial' => abs($refundAmount - (float) $order->getGrandTotal()) > 0.01,
                        'has_reason' => !empty($refundData['reason']),
                        'has_refund_id' => true
                    ])->about([
                        'order_ref' => (string) $order->getIncrementId(),
                        'payment_intent_id' => (string) $paymentIntentId
                    ])
                );
            } else {
                $this->logger->error('Paypercut: Refund API returned unexpected response', [
                    'order_id' => $order->getIncrementId(),
                    'creditmemo_id' => $creditmemo->getIncrementId(),
                    'response' => $result
                ]);

                $this->recorder->record(
                    Event::failure('refund.failed', 'no_refund_id', [
                        'source' => 'credit_memo',
                        'has_reason' => !empty($refundData['reason'])
                    ])->about([
                        'order_ref' => (string) $order->getIncrementId(),
                        'payment_intent_id' => (string) $paymentIntentId
                    ])
                );
            }
        } catch (\Exception $e) {
            // Log error but don't fail the credit memo creation
            $this->logger->error('Paypercut: Failed to create refund', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId(),
                'error' => $e->getMessage()
            ]);

            $attrs = [
                'source' => 'credit_memo',
                'has_reason' => true
            ];

            $this->recorder->record(
                ($e instanceof PaypercutApiException
                    ? Event::apiFailure('refund.failed', $e, $attrs)
                    : Event::failure('refund.failed', 'transport', $attrs, $e)
                )->about([
                    'order_ref' => (string) $order->getIncrementId(),
                    'payment_intent_id' => (string) $paymentIntentId
                ])
            );
            
            // Add error comment to credit memo
            $creditmemo->addComment(
                __('Failed to create Paypercut refund: %1', $e->getMessage())
            );
        }
    }
}
