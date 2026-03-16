<?php
namespace Paypercut\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Class BnplConfigProvider
 * Configuration provider for BNPL (Buy Now Pay Later) payment method
 */
class BnplConfigProvider implements ConfigProviderInterface
{
    const CODE = 'paypercut_bnpl';

    const CONFIG_PATH_ACTIVE = 'payment/paypercut_bnpl/active';
    const CONFIG_PATH_TITLE = 'payment/paypercut_bnpl/title';
    const CONFIG_PATH_MIN_TOTAL = 'payment/paypercut_bnpl/min_order_total';
    const CONFIG_PATH_MAX_TOTAL = 'payment/paypercut_bnpl/max_order_total';
    const CONFIG_PATH_INSTALLMENTS = 'payment/paypercut_bnpl/installments';
    const CONFIG_PATH_SHOW_INSTALLMENT_PREVIEW = 'payment/paypercut_bnpl/show_installment_preview';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     * @param CheckoutSession $checkoutSession
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder,
        CheckoutSession $checkoutSession,
        PriceCurrencyInterface $priceCurrency
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
        $this->checkoutSession = $checkoutSession;
        $this->priceCurrency = $priceCurrency;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $quote = $this->checkoutSession->getQuote();
        $grandTotal = $quote ? $quote->getGrandTotal() : 0;
        $installmentOptions = $this->getInstallmentOptions($grandTotal);

        return [
            'payment' => [
                self::CODE => [
                    'isActive' => $this->isActive(),
                    'isAvailable' => $this->isAvailableForAmount($grandTotal),
                    'installmentOptions' => $installmentOptions,
                    'minOrderTotal' => $this->getMinOrderTotal(),
                    'maxOrderTotal' => $this->getMaxOrderTotal(),
                    'showInstallmentPreview' => $this->isShowInstallmentPreview(),
                    'redirectUrl' => $this->urlBuilder->getUrl('paypercut/bnpl/redirect'),
                    'calculateUrl' => $this->urlBuilder->getUrl('paypercut/bnpl/calculate'),
                ]
            ]
        ];
    }

    /**
     * Check if BNPL is active
     *
     * @return bool
     */
    protected function isActive()
    {
        return (bool) $this->scopeConfig->getValue(self::CONFIG_PATH_ACTIVE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check if installment preview should be shown in checkout
     *
     * @return bool
     */
    protected function isShowInstallmentPreview()
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_SHOW_INSTALLMENT_PREVIEW,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if BNPL is available for the given amount
     *
     * @param float $amount
     * @return bool
     */
    protected function isAvailableForAmount($amount)
    {
        $minTotal = $this->getMinOrderTotal();
        $maxTotal = $this->getMaxOrderTotal();

        return $amount >= $minTotal && $amount <= $maxTotal;
    }

    /**
     * Get minimum order total
     *
     * @return float
     */
    protected function getMinOrderTotal()
    {
        return (float) $this->scopeConfig->getValue(self::CONFIG_PATH_MIN_TOTAL, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get maximum order total
     *
     * @return float
     */
    protected function getMaxOrderTotal()
    {
        return (float) $this->scopeConfig->getValue(self::CONFIG_PATH_MAX_TOTAL, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get available installment options
     *
     * @return array
     */
    protected function getAvailableInstallments()
    {
        $installments = $this->scopeConfig->getValue(self::CONFIG_PATH_INSTALLMENTS, ScopeInterface::SCOPE_STORE);
        if (empty($installments)) {
            return [3, 6, 12];
        }
        return array_map('intval', explode(',', $installments));
    }

    /**
     * Get installment options with calculated amounts
     *
     * @param float $grandTotal
     * @return array
     */
    protected function getInstallmentOptions($grandTotal)
    {
        $options = [];
        $installments = $this->getAvailableInstallments();

        foreach ($installments as $months) {
            $monthlyAmount = $grandTotal / $months;
            $options[] = [
                'months' => $months,
                'monthlyAmount' => $this->priceCurrency->format($monthlyAmount, false),
                'monthlyAmountRaw' => round($monthlyAmount, 2),
                'label' => sprintf('%d rate x %s', $months, $this->priceCurrency->format($monthlyAmount, false))
            ];
        }

        return $options;
    }
}

