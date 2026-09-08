<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Paypercut\Payment\Model\Support\Environment;

/**
 * Builds the one-off environment and configuration snapshots sent at session start.
 *
 * Reads the store's own configuration only. Every value it collects is named
 * explicitly here and cast by Event's declared schema; nothing is harvested by
 * walking a settings array, which is how a credential would end up on the wire.
 * The three `*_configured` values are presence booleans derived from
 * secret-bearing settings — the value never travels, only whether one exists.
 */
class EnvironmentSnapshot
{
    const CONFIG_PATH_THEME_ID = 'design/theme/theme_id';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ThemeProviderInterface
     */
    private $themeProvider;

    /**
     * @var ModuleVersion
     */
    private $moduleVersion;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductMetadataInterface $productMetadata
     * @param StoreManagerInterface $storeManager
     * @param ThemeProviderInterface $themeProvider
     * @param ModuleVersion $moduleVersion
     * @param Environment $environment
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager,
        ThemeProviderInterface $themeProvider,
        ModuleVersion $moduleVersion,
        Environment $environment
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->productMetadata = $productMetadata;
        $this->storeManager = $storeManager;
        $this->themeProvider = $themeProvider;
        $this->moduleVersion = $moduleVersion;
        $this->environment = $environment;
    }

    /**
     * @return array
     */
    public function values(): array
    {
        return [
            'plugin_version' => $this->moduleVersion->get(),
            'magento_version' => $this->productMetadata->getVersion(),
            'magento_edition' => $this->productMetadata->getEdition(),
            'php_version' => PHP_VERSION,
            'theme_name' => $this->themeName(),
            'is_multistore' => !$this->storeManager->hasSingleStore(),
            'is_ssl' => $this->isSecure(),

            // This module is redirect-only on every checkout it supports.
            'checkout_mode' => 'hosted',
            'payment_action' => (string) $this->scopeConfig->getValue('payment/paypercut_card/payment_action'),
            'order_status' => (string) $this->scopeConfig->getValue('payment/paypercut_card/order_status'),
            'refund_action' => (string) $this->scopeConfig->getValue('payment/paypercut_card/refund_action'),
            'saved_payment_methods' => (bool) $this->scopeConfig->getValue('payment/paypercut_vault/active'),
            'card_enabled' => (bool) $this->scopeConfig->getValue('payment/paypercut_card/active'),
            'bnpl_enabled' => (bool) $this->scopeConfig->getValue('payment/paypercut_bnpl/active'),
            'subscriptions_enabled' => (bool) $this->scopeConfig->getValue('payment/paypercut_subscription/active'),
            'logging_enabled' => (bool) $this->scopeConfig->getValue('payment/paypercut_card/debug'),
            'connection_environment' => $this->environment->getEnvironment(),
            'api_key_configured' => '' !== (string) $this->scopeConfig->getValue(TelemetrySession::CONFIG_PATH_SECRET_KEY),
            'webhook_configured' => '' !== (string) $this->scopeConfig->getValue(TelemetrySession::CONFIG_PATH_WEBHOOK_SECRET),
            'bnpl_key_configured' => '' !== (string) $this->scopeConfig->getValue(TelemetrySession::CONFIG_PATH_BNPL_SECRET_KEY),
            'bnpl_installments' => (string) $this->scopeConfig->getValue('payment/paypercut_bnpl/installments'),
            'bnpl_show_installment_preview' => (bool) $this->scopeConfig->getValue('payment/paypercut_bnpl/show_installment_preview'),
            'subscription_collection_method' => (string) $this->scopeConfig->getValue('payment/paypercut_subscription/collection_method'),
        ];
    }

    /**
     * @return string
     */
    private function themeName(): string
    {
        $themeId = (string) $this->scopeConfig->getValue(self::CONFIG_PATH_THEME_ID);

        if ($themeId === '') {
            return '';
        }

        try {
            $theme = $this->themeProvider->getThemeById((int) $themeId);
        } catch (\Exception $exception) {
            return '';
        }

        if ($theme === null || !$theme->getId()) {
            return '';
        }

        return (string) ($theme->getCode() ?: $theme->getThemeTitle());
    }

    /**
     * @return bool
     */
    private function isSecure(): bool
    {
        try {
            return (bool) $this->storeManager->getStore()->isCurrentlySecure();
        } catch (\Exception $exception) {
            return false;
        }
    }
}
