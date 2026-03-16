<?php
namespace Paypercut\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'paypercut_card';
    const VAULT_CODE = 'paypercut_vault';

    const CONFIG_PATH_DESCRIPTION = 'payment/paypercut_card/description';
    const CONFIG_PATH_ACTIVE = 'payment/paypercut_card/active';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'isActive' => $this->isActive(),
                    'description' => $this->getDescription(),
                    'vaultCode' => self::VAULT_CODE,
                    'redirectUrl' => $this->urlBuilder->getUrl('paypercut/payment/redirect'),
                    'successUrl' => $this->urlBuilder->getUrl('paypercut/payment/success'),
                    'cancelUrl' => $this->urlBuilder->getUrl('paypercut/payment/cancel'),
                ]
            ]
        ];
    }

    /**
     * Check if payment method is active
     *
     * @return bool
     */
    protected function isActive()
    {
        return (bool) $this->scopeConfig->getValue(self::CONFIG_PATH_ACTIVE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get payment method description
     *
     * @return string|null
     */
    protected function getDescription()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DESCRIPTION, ScopeInterface::SCOPE_STORE);
    }
}
