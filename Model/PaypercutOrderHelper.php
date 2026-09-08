<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Paypercut\Payment\Model\Api\Client;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Psr\Log\LoggerInterface;

/**
 * Shared helper for resolving Paypercut customer and payment method from an order.
 * Used by both the IPN controller and the subscription observer.
 */
class PaypercutOrderHelper
{
    /**
     * @var Client
     */
    private $apiClient;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var PaymentTokenManagementInterface
     */
    private $paymentTokenManagement;

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
     * @param OrderRepositoryInterface $orderRepository
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param LoggerInterface $logger
     * @param EventRecorder $recorder
     */
    public function __construct(
        Client $apiClient,
        OrderRepositoryInterface $orderRepository,
        PaymentTokenManagementInterface $paymentTokenManagement,
        LoggerInterface $logger,
        EventRecorder $recorder
    ) {
        $this->apiClient = $apiClient;
        $this->orderRepository = $orderRepository;
        $this->paymentTokenManagement = $paymentTokenManagement;
        $this->logger = $logger;
        $this->recorder = $recorder;
    }

    /**
     * Get or create Paypercut customer ID for an order
     *
     * @param OrderInterface $order
     * @return string
     * @throws \Exception
     */
    public function getOrCreateCustomer(OrderInterface $order): string
    {
        $payment = $order->getPayment();

        $existingCustomerId = $payment->getAdditionalInformation('paypercut_customer_id');
        if ($existingCustomerId) {
            return $existingCustomerId;
        }

        $customerData = [
            'email' => $order->getCustomerEmail(),
            'name' => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
            'metadata' => [
                'magento_customer_id' => $order->getCustomerId() ?: 'guest',
                'magento_order_id' => $order->getIncrementId()
            ]
        ];

        $billingAddress = $order->getBillingAddress();
        if ($billingAddress && $billingAddress->getTelephone()) {
            $customerData['phone'] = $billingAddress->getTelephone();
        }

        $result = $this->apiClient->createCustomer($customerData);

        if (!empty($result['id'])) {
            $payment->setAdditionalInformation('paypercut_customer_id', $result['id']);
            return $result['id'];
        }

        throw new \Exception('Failed to create Paypercut customer');
    }

    /**
     * Get payment method ID from order payment data, checkout session, or vault token
     *
     * @param OrderInterface $order
     * @param string $customerId
     * @return string|null
     */
    public function getPaymentMethodId(OrderInterface $order, string $customerId): ?string
    {
        $payment = $order->getPayment();

        // Check for payment method ID already saved in additional_information
        $paymentMethodId = $payment->getAdditionalInformation('paypercut_payment_method_id');
        if ($paymentMethodId) {
            $this->logger->info('Paypercut: Using saved payment method ID', [
                'payment_method_id' => $paymentMethodId
            ]);
            $this->recorder->record(
                Event::of('payment_method.already_saved')
                    ->about(['order_ref' => (string) $order->getIncrementId()])
            );
            return $paymentMethodId;
        }

        // Try to get payment method from checkout session
        $checkoutId = $payment->getAdditionalInformation('paypercut_id');
        if ($checkoutId) {
            try {
                $this->logger->info('Paypercut: Retrieving payment method from checkout session', [
                    'checkout_id' => $checkoutId
                ]);

                $checkout = $this->apiClient->getCheckout($checkoutId);

                $paymentMethodId = $checkout['payment_method']
                    ?? $checkout['default_payment_method']
                    ?? $checkout['payment_intent_data']['payment_method']
                    ?? null;

                if ($paymentMethodId) {
                    $payment->setAdditionalInformation('paypercut_payment_method_id', $paymentMethodId);
                    $this->orderRepository->save($order);

                    $this->logger->info('Paypercut: Payment method retrieved from checkout', [
                        'payment_method_id' => $paymentMethodId
                    ]);

                    $this->recorder->record(
                        Event::of('payment_method.added', ['source' => 'checkout_session'])
                            ->about([
                                'order_ref' => (string) $order->getIncrementId(),
                                'payment_id' => (string) $checkoutId
                            ])
                    );

                    return $paymentMethodId;
                }

                $this->logger->warning('Paypercut: No payment method found in checkout response', [
                    'checkout_id' => $checkoutId
                ]);

                $this->recorder->record(
                    Event::failure('payment_method.add_failed', 'token_not_saved', [
                        'source' => 'checkout_session'
                    ])->about(['order_ref' => (string) $order->getIncrementId()])
                );
            } catch (\Exception $e) {
                $this->logger->warning('Paypercut: Failed to retrieve checkout session', [
                    'checkout_id' => $checkoutId,
                    'error' => $e->getMessage()
                ]);

                $this->recorder->record(
                    Event::failure('payment_method.add_failed', 'lookup_failed', [
                        'source' => 'checkout_session'
                    ], $e)->about(['order_ref' => (string) $order->getIncrementId()])
                );
            }
        } else {
            $this->recorder->record(
                Event::failure('payment_method.add_failed', 'no_session_id')
                    ->about(['order_ref' => (string) $order->getIncrementId()])
            );
        }

        // Check for vault token
        $vaultToken = $payment->getAdditionalInformation('public_hash');
        if ($vaultToken && $order->getCustomerId()) {
            $token = $this->paymentTokenManagement->getByPublicHash(
                $vaultToken,
                (int) $order->getCustomerId()
            );

            if ($token) {
                $gatewayToken = $token->getGatewayToken();
                if ($gatewayToken) {
                    return $gatewayToken;
                }
            }
        }

        $this->logger->warning('Paypercut: No payment method available', [
            'order_id' => $order->getIncrementId()
        ]);

        $this->recorder->record(
            Event::failure('payment_method.add_failed', 'no_payment_method', [
                'source' => 'vault',
                'has_customer_id' => (bool) $order->getCustomerId()
            ])->about(['order_ref' => (string) $order->getIncrementId()])
        );

        return null;
    }
}
