<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Subscription;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Paypercut\Payment\Api\SubscriptionManagementInterface;
use Paypercut\Payment\Model\Api\Client;
use Psr\Log\LoggerInterface;

class SubscriptionManager implements SubscriptionManagementInterface
{
    const CONFIG_PATH_ACTIVE = 'payment/paypercut_subscription/active';
    const CONFIG_PATH_BILLING_INTERVAL = 'payment/paypercut_subscription/billing_interval';
    const CONFIG_PATH_BILLING_INTERVAL_COUNT = 'payment/paypercut_subscription/billing_interval_count';
    const CONFIG_PATH_BILLING_DAY = 'payment/paypercut_subscription/billing_day';
    const CONFIG_PATH_COLLECTION_METHOD = 'payment/paypercut_subscription/collection_method';
    const CONFIG_PATH_ENABLE_TRIAL = 'payment/paypercut_subscription/enable_trial';
    const CONFIG_PATH_TRIAL_DAYS = 'payment/paypercut_subscription/trial_days';
    const CONFIG_PATH_TRIAL_MISSING_PAYMENT = 'payment/paypercut_subscription/trial_missing_payment';
    const CONFIG_PATH_CANCEL_AT_PERIOD_END = 'payment/paypercut_subscription/cancel_at_period_end';

    /**
     * @var Client
     */
    private $apiClient;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var PaymentTokenManagementInterface
     */
    private $paymentTokenManagement;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Client $apiClient
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductRepositoryInterface $productRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $apiClient,
        ScopeConfigInterface $scopeConfig,
        ProductRepositoryInterface $productRepository,
        CustomerRepositoryInterface $customerRepository,
        PaymentTokenManagementInterface $paymentTokenManagement,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->scopeConfig = $scopeConfig;
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->logger = $logger;
    }

    /**
     * Check if subscriptions are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if a product is subscription-enabled
     *
     * @param int $productId
     * @return bool
     */
    public function isProductSubscriptionEnabled(int $productId): bool
    {
        try {
            $product = $this->productRepository->getById($productId);
            return (bool) $product->getData('paypercut_subscription_enabled');
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Create subscription for order items that are subscription-enabled
     *
     * @param OrderInterface $order
     * @param string $paypercutCustomerId
     * @param string|null $paymentMethodId
     * @return array Created subscriptions
     * @throws LocalizedException
     */
    public function createSubscriptionsFromOrder(
        OrderInterface $order,
        string $paypercutCustomerId,
        ?string $paymentMethodId = null
    ): array {
        if (!$this->isEnabled((int) $order->getStoreId())) {
            return [];
        }

        $subscriptions = [];
        $subscriptionItems = $this->getSubscriptionItemsFromOrder($order);

        foreach ($subscriptionItems as $item) {
            try {
                $subscriptionData = $this->buildSubscriptionData(
                    $item,
                    $paypercutCustomerId,
                    $paymentMethodId,
                    $order
                );

                $result = $this->apiClient->createSubscription($subscriptionData);
                $subscriptions[] = $result;

                $this->logger->info('Paypercut: Subscription created', [
                    'order_id' => $order->getEntityId(),
                    'item_sku' => $item->getSku(),
                    'subscription_id' => $result['id'] ?? null
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Paypercut: Failed to create subscription', [
                    'order_id' => $order->getEntityId(),
                    'item_sku' => $item->getSku(),
                    'error' => $e->getMessage()
                ]);
                throw new LocalizedException(
                    __('Failed to create subscription for %1: %2', $item->getName(), $e->getMessage())
                );
            }
        }

        return $subscriptions;
    }

    /**
     * Get subscription-enabled items from order
     *
     * @param OrderInterface $order
     * @return OrderItemInterface[]
     */
    public function getSubscriptionItemsFromOrder(OrderInterface $order): array
    {
        $subscriptionItems = [];

        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // Skip child items
            }

            if ($this->isProductSubscriptionEnabled((int) $item->getProductId())) {
                $subscriptionItems[] = $item;
            }
        }

        return $subscriptionItems;
    }

    /**
     * Check if order contains subscription items
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function orderHasSubscriptionItems(OrderInterface $order): bool
    {
        return count($this->getSubscriptionItemsFromOrder($order)) > 0;
    }

    /**
     * Build subscription data for API request
     *
     * @param OrderItemInterface $item
     * @param string $customerId
     * @param string|null $paymentMethodId
     * @param OrderInterface $order
     * @return array
     */
    private function buildSubscriptionData(
        OrderItemInterface $item,
        string $customerId,
        ?string $paymentMethodId,
        OrderInterface $order
    ): array {
        $storeId = (int) $order->getStoreId();
        $product = $this->productRepository->getById($item->getProductId());

        // Get billing configuration (product-level overrides or defaults)
        $interval = $product->getData('paypercut_subscription_interval')
            ?: $this->getConfigValue(self::CONFIG_PATH_BILLING_INTERVAL, $storeId);
        $intervalCount = $product->getData('paypercut_subscription_interval_count')
            ?: (int) $this->getConfigValue(self::CONFIG_PATH_BILLING_INTERVAL_COUNT, $storeId);
        $billingDay = $this->getConfigValue(self::CONFIG_PATH_BILLING_DAY, $storeId);
        $collectionMethod = $this->getConfigValue(self::CONFIG_PATH_COLLECTION_METHOD, $storeId);
        $cancelAtPeriodEnd = (bool) $this->getConfigValue(self::CONFIG_PATH_CANCEL_AT_PERIOD_END, $storeId);

        // Subscription price (product override or item price)
        $subscriptionPrice = $product->getData('paypercut_subscription_price');
        $unitAmount = $subscriptionPrice
            ? (int) round($subscriptionPrice * 100)
            : (int) round($item->getPrice() * 100);

        $data = [
            'customer' => $customerId,
            'collection_method' => $collectionMethod,
            'currency' => $order->getOrderCurrencyCode(),
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'billing_cycle_anchor' => 1,
            'pending_invoice_item_interval' => [
                'interval' => $interval,
                'interval_count' => $intervalCount
            ],
            'items' => [
                [
                    'quantity' => (int) $item->getQtyOrdered(),
                    'price_data' => [
                        'currency' => $order->getOrderCurrencyCode(),
                        'product' => $item->getSku(),
                        'unit_amount' => $unitAmount,
                        'unit_amount_decimal' => (string) $unitAmount
                    ]
                ]
            ],
            'metadata' => [
                'magento_order_id' => $order->getIncrementId(),
                'magento_product_id' => $item->getProductId(),
                'magento_item_id' => $item->getItemId()
            ]
        ];

        // Only include default_payment_method if provided
        if ($paymentMethodId) {
            $data['default_payment_method'] = $paymentMethodId;
        }

        // Add billing day anchor if configured
        if ($billingDay) {
            $data['billing_cycle_anchor_config'] = [
                'day_of_month' => (int) $billingDay,
                'hour' => null,
                'minute' => null,
                'month' => null,
                'second' => null
            ];
        }

        // Add max billing cycles if product has limit
        $maxCycles = $product->getData('paypercut_subscription_cycles');
        if ($maxCycles) {
            $data['cancel_at'] = $this->calculateCancelDate($interval, $intervalCount, (int) $maxCycles);
        }

        // Add trial settings if enabled
        if ($this->getConfigValue(self::CONFIG_PATH_ENABLE_TRIAL, $storeId)) {
            $trialDays = (int) $this->getConfigValue(self::CONFIG_PATH_TRIAL_DAYS, $storeId);
            $missingPaymentBehavior = $this->getConfigValue(self::CONFIG_PATH_TRIAL_MISSING_PAYMENT, $storeId);

            if ($trialDays > 0) {
                $data['trial_end'] = strtotime("+{$trialDays} days");
                $data['trial_settings'] = [
                    'end_behavior' => [
                        'missing_payment_method' => $missingPaymentBehavior
                    ]
                ];
            }
        }

        return $data;
    }

    /**
     * Calculate cancel date based on max billing cycles
     *
     * @param string $interval
     * @param int $intervalCount
     * @param int $maxCycles
     * @return int Unix timestamp
     */
    private function calculateCancelDate(string $interval, int $intervalCount, int $maxCycles): int
    {
        $totalIntervals = $intervalCount * $maxCycles;
        
        switch ($interval) {
            case 'day':
                return strtotime("+{$totalIntervals} days");
            case 'week':
                return strtotime("+{$totalIntervals} weeks");
            case 'month':
                return strtotime("+{$totalIntervals} months");
            case 'year':
                return strtotime("+{$totalIntervals} years");
            default:
                return strtotime("+{$totalIntervals} months");
        }
    }

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
    ): array {
        $cancellationDetails = [];
        
        if ($reason || $feedback) {
            $cancellationDetails['cancellation_details'] = [
                'comment' => $reason ?? '',
                'feedback' => $feedback ?? ''
            ];
        }

        return $this->apiClient->cancelSubscription($subscriptionId, $cancellationDetails);
    }

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
    ): array {
        $pauseData = [
            'pause_collection' => [
                'behavior' => $behavior,
                'resumes_at' => $resumesAt
            ]
        ];

        return $this->apiClient->pauseSubscription($subscriptionId, $pauseData);
    }

    /**
     * Resume a paused subscription
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->apiClient->resumeSubscription($subscriptionId);
    }

    /**
     * Get customer's subscriptions
     *
     * @param string $paypercutCustomerId
     * @param string|null $status Filter by status (active, paused, canceled, etc.)
     * @return array
     * @throws \Exception
     */
    public function getCustomerSubscriptions(string $paypercutCustomerId, ?string $status = null): array
    {
        $params = [];
        if ($status) {
            $params['status'] = $status;
        }

        return $this->apiClient->listSubscriptions($paypercutCustomerId, $params);
    }

    /**
     * Get subscription details
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->apiClient->getSubscription($subscriptionId);
    }

    /**
     * Get config value
     *
     * @param string $path
     * @param int|null $storeId
     * @return mixed
     */
    private function getConfigValue(string $path, ?int $storeId = null)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}

