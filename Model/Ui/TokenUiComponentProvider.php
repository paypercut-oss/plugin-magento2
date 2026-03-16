<?php
namespace Paypercut\Payment\Model\Ui;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterface;
use Magento\Vault\Model\Ui\TokenUiComponentProviderInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterfaceFactory;
use Magento\Framework\UrlInterface;

/**
 * Class TokenUiComponentProvider
 * Provides UI component for vault token display
 */
class TokenUiComponentProvider implements TokenUiComponentProviderInterface
{
    /**
     * @var TokenUiComponentInterfaceFactory
     */
    private $componentFactory;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @param TokenUiComponentInterfaceFactory $componentFactory
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        TokenUiComponentInterfaceFactory $componentFactory,
        UrlInterface $urlBuilder
    ) {
        $this->componentFactory = $componentFactory;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Get UI component for token
     *
     * @param PaymentTokenInterface $paymentToken
     * @return TokenUiComponentInterface
     */
    public function getComponentForToken(PaymentTokenInterface $paymentToken)
    {
        $jsonDetails = json_decode($paymentToken->getTokenDetails() ?: '{}', true);

        return $this->componentFactory->create(
            [
                'config' => [
                    'code' => ConfigProvider::VAULT_CODE,
                    TokenUiComponentProviderInterface::COMPONENT_DETAILS => $jsonDetails,
                    TokenUiComponentProviderInterface::COMPONENT_PUBLIC_HASH => $paymentToken->getPublicHash()
                ],
                'name' => 'Paypercut_Payment/js/view/payment/method-renderer/vault'
            ]
        );
    }
}
