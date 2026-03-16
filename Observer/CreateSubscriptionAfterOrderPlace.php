<?php
declare(strict_types=1);

namespace Paypercut\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Paypercut\Payment\Api\SubscriptionManagementInterface;
use Paypercut\Payment\Model\PaypercutOrderHelper;
use Paypercut\Payment\Model\Ui\ConfigProvider;
use Psr\Log\LoggerInterface;

/**
 * Observer to create subscriptions after order is placed with Paypercut payment
 */
class CreateSubscriptionAfterOrderPlace implements ObserverInterface
{
    /**
     * @var SubscriptionManagementInterface
     */
    private $subscriptionManager;

    /**
     * @var PaypercutOrderHelper
     */
    private $orderHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SubscriptionManagementInterface $subscriptionManager
     * @param PaypercutOrderHelper $orderHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        SubscriptionManagementInterface $subscriptionManager,
        PaypercutOrderHelper $orderHelper,
        LoggerInterface $logger
    ) {
        $this->subscriptionManager = $subscriptionManager;
        $this->orderHelper = $orderHelper;
        $this->logger = $logger;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order) {
            return;
        }

        // Check if this is a Paypercut payment
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        // Check if subscriptions were already created by IPN
        $existingSubscriptions = $payment->getAdditionalInformation('paypercut_subscription_ids');
        if ($existingSubscriptions) {
            $this->logger->info('Paypercut Observer: Subscriptions already created by IPN, skipping', [
                'order_id' => $order->getEntityId()
            ]);
            return;
        }

        // Check if subscriptions are enabled and order has subscription items
        if (!$this->subscriptionManager->isEnabled((int) $order->getStoreId())) {
            return;
        }

        if (!$this->subscriptionManager->orderHasSubscriptionItems($order)) {
            return;
        }

        // Note: This observer now acts as a backup. Subscriptions should be created
        // by IPN after payment confirmation. This only runs if IPN hasn't processed yet.
        $this->logger->info('Paypercut Observer: Attempting to create subscriptions (backup flow)', [
            'order_id' => $order->getEntityId()
        ]);

        try {
            // Get or create Paypercut customer
            $paypercutCustomerId = $this->orderHelper->getOrCreateCustomer($order);

            // Get the payment method ID (from vault token or current transaction) - optional
            $paymentMethodId = $this->orderHelper->getPaymentMethodId($order, $paypercutCustomerId);

            if (!$paymentMethodId) {
                $this->logger->info('Paypercut Observer: Creating subscription without payment method (will use invoice collection)', [
                    'order_id' => $order->getEntityId()
                ]);
            }

            // Create subscriptions for subscription-enabled items
            $subscriptions = $this->subscriptionManager->createSubscriptionsFromOrder(
                $order,
                $paypercutCustomerId,
                $paymentMethodId
            );

            // Store subscription IDs in order
            if (!empty($subscriptions)) {
                $subscriptionIds = array_column($subscriptions, 'id');
                $payment->setAdditionalInformation('paypercut_subscription_ids', $subscriptionIds);

                $this->logger->info('Paypercut: Subscriptions created for order', [
                    'order_id' => $order->getEntityId(),
                    'subscription_ids' => $subscriptionIds
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the order
            $this->logger->error('Paypercut: Failed to create subscriptions', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
