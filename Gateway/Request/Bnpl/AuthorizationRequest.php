<?php
namespace Paypercut\Payment\Gateway\Request\Bnpl;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Class AuthorizationRequest
 * Builds BNPL authorization request based on Paypercut BNPL API
 * @see https://docs.paypercut.io/api-reference
 */
class AuthorizationRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * Builds BNPL authorization request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $order = $paymentDO->getOrder();
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException('Order payment should be provided.');
        }

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        // Get selected installment plan
        $installments = $payment->getAdditionalInformation('installments') ?: 3;

        return [
            'TXN_TYPE' => 'BNPL_AUTH',
            'INVOICE' => $order->getOrderIncrementId(),
            'AMOUNT' => $order->getGrandTotalAmount(),
            'CURRENCY' => $order->getCurrencyCode(),
            'INSTALLMENTS' => (int) $installments,
            
            // Customer data
            'CUSTOMER' => [
                'email' => $billingAddress->getEmail(),
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'phone' => $billingAddress->getTelephone(),
            ],
            
            // Billing address
            'BILLING_ADDRESS' => [
                'street' => implode(' ', $billingAddress->getStreet()),
                'city' => $billingAddress->getCity(),
                'region' => $billingAddress->getRegionCode(),
                'postcode' => $billingAddress->getPostcode(),
                'country' => $billingAddress->getCountryId(),
            ],
            
            // Shipping address
            'SHIPPING_ADDRESS' => $shippingAddress ? [
                'street' => implode(' ', $shippingAddress->getStreet()),
                'city' => $shippingAddress->getCity(),
                'region' => $shippingAddress->getRegionCode(),
                'postcode' => $shippingAddress->getPostcode(),
                'country' => $shippingAddress->getCountryId(),
            ] : null,

            // Order items for BNPL validation
            'ITEMS' => $this->getOrderItems($order),
            
            // API credentials
            'MERCHANT_KEY' => $this->config->getValue('api_key', $order->getStoreId())
        ];
    }

    /**
     * Get order items array
     *
     * @param \Magento\Payment\Gateway\Data\OrderAdapterInterface $order
     * @return array
     */
    private function getOrderItems($order)
    {
        $items = [];
        
        foreach ($order->getItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }
            
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price' => $item->getPrice(),
            ];
        }
        
        return $items;
    }
}

