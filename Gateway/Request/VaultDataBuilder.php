<?php
namespace Paypercut\Payment\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;

/**
 * Class VaultDataBuilder
 * Adds vault flag to request if customer opted to save card
 */
class VaultDataBuilder implements BuilderInterface
{
    /**
     * Additional options in request to gateway
     */
    const OPTIONS = 'options';

    /**
     * The option that determines whether the payment method should be stored
     */
    const STORE_IN_VAULT = 'storeInVault';

    /**
     * Builds vault data
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

        $paymentDO = $buildSubject['payment'];
        $payment = $paymentDO->getPayment();

        $data = [];

        if ($payment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE)) {
            $data[self::OPTIONS] = [
                self::STORE_IN_VAULT => true
            ];
        }

        return $data;
    }
}

