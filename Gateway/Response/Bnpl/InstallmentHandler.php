<?php
namespace Paypercut\Payment\Gateway\Response\Bnpl;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Class InstallmentHandler
 * Handles BNPL installment data from gateway response
 */
class InstallmentHandler implements HandlerInterface
{
    /**
     * Handles BNPL specific response data
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            return;
        }

        // Store BNPL specific data
        if (isset($response['BNPL_ORDER_ID'])) {
            $payment->setAdditionalInformation('bnpl_order_id', $response['BNPL_ORDER_ID']);
        }

        if (isset($response['INSTALLMENT_PLAN'])) {
            $payment->setAdditionalInformation('installment_plan', $response['INSTALLMENT_PLAN']);
        }

        if (isset($response['MONTHLY_AMOUNT'])) {
            $payment->setAdditionalInformation('monthly_amount', $response['MONTHLY_AMOUNT']);
        }

        if (isset($response['FIRST_PAYMENT_DATE'])) {
            $payment->setAdditionalInformation('first_payment_date', $response['FIRST_PAYMENT_DATE']);
        }

        if (isset($response['REDIRECT_URL'])) {
            $payment->setAdditionalInformation('redirect_url', $response['REDIRECT_URL']);
        }
    }
}

