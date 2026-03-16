<?php
namespace Paypercut\Payment\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Class RefundRequest
 */
class RefundRequest implements BuilderInterface
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
     * Builds refund request
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

        $amount = $buildSubject['amount'] ?? $order->getGrandTotalAmount();

        return [
            'TXN_TYPE' => 'R',
            'TXN_ID' => $payment->getParentTransactionId() ?: $payment->getLastTransId(),
            'AMOUNT' => $amount,
            'CURRENCY' => $order->getCurrencyCode(),
            'INVOICE' => $order->getOrderIncrementId(),
            'MERCHANT_KEY' => $this->config->getValue('api_key', $order->getStoreId())
        ];
    }
}

