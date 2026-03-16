<?php
namespace Paypercut\Payment\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Paypercut\Payment\Model\Ui\ConfigProvider;

/**
 * Class VaultDetailsHandler
 * Handles saving payment token for vault
 */
class VaultDetailsHandler implements HandlerInterface
{
    /**
     * @var PaymentTokenFactoryInterface
     */
    protected $paymentTokenFactory;

    /**
     * @var OrderPaymentExtensionInterfaceFactory
     */
    protected $paymentExtensionFactory;

    /**
     * @param PaymentTokenFactoryInterface $paymentTokenFactory
     * @param OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory
     */
    public function __construct(
        PaymentTokenFactoryInterface $paymentTokenFactory,
        OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory
    ) {
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
    }

    /**
     * @inheritdoc
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        // Check if vault token was returned from gateway
        if (!isset($response['VAULT_TOKEN'])) {
            return;
        }

        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();

        // Create payment token
        $paymentToken = $this->createVaultPaymentToken($response);
        
        if ($paymentToken !== null) {
            $extensionAttributes = $this->getExtensionAttributes($payment);
            $extensionAttributes->setVaultPaymentToken($paymentToken);
        }
    }

    /**
     * Create vault payment token
     *
     * @param array $response
     * @return PaymentTokenInterface|null
     */
    protected function createVaultPaymentToken(array $response)
    {
        if (empty($response['VAULT_TOKEN'])) {
            return null;
        }

        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
        
        $paymentToken->setGatewayToken($response['VAULT_TOKEN']);
        $paymentToken->setExpiresAt($this->getExpirationDate($response));
        
        $paymentToken->setTokenDetails($this->convertDetailsToJSON([
            'type' => $response['CARD_TYPE'] ?? 'VI',
            'maskedCC' => $response['MASKED_CARD'] ?? '****',
            'expirationDate' => $response['CARD_EXPIRY'] ?? ''
        ]));

        return $paymentToken;
    }

    /**
     * Get expiration date
     *
     * @param array $response
     * @return string
     */
    private function getExpirationDate(array $response)
    {
        if (!empty($response['CARD_EXPIRY'])) {
            $expDate = $response['CARD_EXPIRY'];
            $expYear = '20' . substr($expDate, -2);
            $expMonth = substr($expDate, 0, 2);
            
            return sprintf('%s-%s-01 00:00:00', $expYear, $expMonth);
        }
        
        // Default to 3 years from now
        return date('Y-m-d 00:00:00', strtotime('+3 years'));
    }

    /**
     * Convert details to JSON string
     *
     * @param array $details
     * @return string
     */
    private function convertDetailsToJSON(array $details)
    {
        $json = \Magento\Framework\Serialize\Serializer\Json::class;
        return json_encode($details);
    }

    /**
     * Get payment extension attributes
     *
     * @param InfoInterface $payment
     * @return OrderPaymentExtensionInterface
     */
    private function getExtensionAttributes(InfoInterface $payment)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if (null === $extensionAttributes) {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }
        return $extensionAttributes;
    }
}

