<?php
namespace Paypercut\Payment\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Payment\Gateway\ConfigInterface;

/**
 * Class TokenDataBuilder
 * Adds payment token to request for vault transactions
 */
class TokenDataBuilder implements BuilderInterface
{
    /**
     * @var PaymentTokenManagementInterface
     */
    private $tokenManagement;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param ConfigInterface $config
     */
    public function __construct(
        PaymentTokenManagementInterface $tokenManagement,
        ConfigInterface $config
    ) {
        $this->tokenManagement = $tokenManagement;
        $this->config = $config;
    }

    /**
     * Builds token data for vault transactions
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
        $extensionAttributes = $payment->getExtensionAttributes();
        
        $paymentToken = $extensionAttributes->getVaultPaymentToken();
        
        if ($paymentToken === null) {
            return [];
        }

        return [
            'TOKEN' => $paymentToken->getGatewayToken(),
            'CUSTOMER_ID' => $paymentToken->getCustomerId()
        ];
    }
}

