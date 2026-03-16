<?php
namespace Paypercut\Payment\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;

class BnplPaymentMethod extends AbstractMethod
{
    const CODE = 'paypercut_bnpl';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var bool
     */
    protected $_canVoid = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentData $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param UrlInterface $urlBuilder
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Check if payment method is available
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote);
    }

    /**
     * Get redirect URL after order placement
     * This is used by Hyva Checkout and standard Magento checkout
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('paypercut/payment/redirect');
    }
}
