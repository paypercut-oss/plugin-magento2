<?php
declare(strict_types=1);

namespace Paypercut\Payment\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Interface for managing Paypercut subscriptions
 * @api
 */
interface SubscriptionManagementInterface
{
    /**
     * Check if subscriptions are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * Check if a product is subscription-enabled
     *
     * @param int $productId
     * @return bool
     */
    public function isProductSubscriptionEnabled(int $productId): bool;

    /**
     * Create subscriptions from order for subscription-enabled items
     *
     * @param OrderInterface $order
     * @param string $paypercutCustomerId
     * @param string|null $paymentMethodId
     * @return array Created subscriptions data
     * @throws LocalizedException
     */
    public function createSubscriptionsFromOrder(
        OrderInterface $order,
        string $paypercutCustomerId,
        ?string $paymentMethodId = null
    ): array;

    /**
     * Check if order contains subscription items
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function orderHasSubscriptionItems(OrderInterface $order): bool;

    /**
     * Cancel a subscription
     *
     * @param string $subscriptionId
     * @param string|null $reason
     * @param string|null $feedback
     * @return array
     * @throws \Exception
     */
    public function cancelSubscription(
        string $subscriptionId,
        ?string $reason = null,
        ?string $feedback = null
    ): array;

    /**
     * Pause a subscription
     *
     * @param string $subscriptionId
     * @param string $behavior
     * @param int|null $resumesAt
     * @return array
     * @throws \Exception
     */
    public function pauseSubscription(
        string $subscriptionId,
        string $behavior = 'keep_as_draft',
        ?int $resumesAt = null
    ): array;

    /**
     * Resume a paused subscription
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function resumeSubscription(string $subscriptionId): array;

    /**
     * Get customer's subscriptions
     *
     * @param string $paypercutCustomerId
     * @param string|null $status
     * @return array
     * @throws \Exception
     */
    public function getCustomerSubscriptions(string $paypercutCustomerId, ?string $status = null): array;

    /**
     * Get subscription details
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function getSubscription(string $subscriptionId): array;
}

