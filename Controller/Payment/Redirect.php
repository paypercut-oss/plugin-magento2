<?php
namespace Paypercut\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Paypercut\Payment\Model\Api\Client as PaypercutClient;
use Psr\Log\LoggerInterface;

/**
 * Class Redirect
 * Redirects customer to Paypercut payment page
 */
class Redirect implements HttpGetActionInterface
{
    private const PLUGIN_VERSION = '1.1.2';

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var PaypercutClient
     */
    private $paypercutClient;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RedirectFactory $redirectFactory
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param PaypercutClient $paypercutClient
     * @param UrlInterface $urlBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductMetadataInterface $productMetadata
     * @param LoggerInterface $logger
     */
    public function __construct(
        RedirectFactory $redirectFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        PaypercutClient $paypercutClient,
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        ProductMetadataInterface $productMetadata,
        LoggerInterface $logger
    ) {
        $this->redirectFactory = $redirectFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->paypercutClient = $paypercutClient;
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->productMetadata = $productMetadata;
        $this->logger = $logger;
    }

    /**
     * Execute redirect
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        $this->logger->info('Paypercut: Redirect controller executed');

        try {
            $order = $this->checkoutSession->getLastRealOrder();
            
            if (!$order || !$order->getId()) {
                $this->logger->error('Paypercut: Order not found in session');
                throw new \Exception('Order not found');
            }

            $payment = $order->getPayment();
            $paymentMethod = $payment->getMethod();

            $this->logger->info('Paypercut: Processing order', [
                'order_id' => $order->getIncrementId(),
                'grand_total' => $order->getGrandTotal(),
                'currency' => $order->getOrderCurrencyCode(),
                'payment_method' => $paymentMethod
            ]);

            // Check if this is BNPL or standard card payment
            if ($paymentMethod === 'paypercut_bnpl') {
                $response = $this->processBnplPayment($order, $payment);
                // BNPL returns 'redirect_url' and 'attempt_id'
                $redirectUrl = $response['redirect_url'] ?? null;
                $paypercutId = $response['attempt_id'] ?? null;
            } else {
                $response = $this->processCardPayment($order, $payment);
                // Standard checkout returns 'url' and 'id'
                $redirectUrl = $response['url'] ?? null;
                $paypercutId = $response['id'] ?? null;
            }

            if (!$redirectUrl) {
                $this->logger->error('Paypercut: Missing URL in response', ['response' => $response]);
                throw new \Exception('Invalid response from Paypercut API: missing redirect URL');
            }

            // Store response data in payment additional info
            $payment->setAdditionalInformation('paypercut_id', $paypercutId);
            $payment->setAdditionalInformation('redirect_url', $redirectUrl);
            
            // Save payment_intent ID if available (useful for tracking)
            if (!empty($response['payment_intent'])) {
                $payment->setAdditionalInformation('paypercut_payment_intent', $response['payment_intent']);
                
                $this->logger->info('Paypercut: Payment intent ID saved', [
                    'order_id' => $order->getIncrementId(),
                    'payment_intent' => $response['payment_intent']
                ]);
            }
            
            // Note: payment_method ID will be saved by IPN after customer completes payment
            
            $this->orderRepository->save($order);

            $this->logger->info('Paypercut: Redirect URL obtained', [
                'order_id' => $order->getIncrementId(),
                'paypercut_id' => $paypercutId,
                'redirect_url' => $redirectUrl
            ]);

            // Redirect to Paypercut
            $redirect->setUrl($redirectUrl);

        } catch (\Exception $e) {
            $this->logger->error('Paypercut redirect error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Restore cart on error
            $this->checkoutSession->restoreQuote();
            
            $redirect->setPath('checkout/cart');
        }

        return $redirect;
    }

    /**
     * Process standard card payment
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     * @throws \Exception
     */
    private function processCardPayment($order, $payment): array
    {
        $this->logger->info('Paypercut: Processing CARD payment');

        // Build line items from order items
        $lineItems = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $lineItems[] = [
                'name' => $item->getName(),
                'quantity' => (int) $item->getQtyOrdered(),
                'unit_price' => (int) round($item->getPrice() * 100) // Convert to cents
            ];
        }

        // Create checkout session with Paypercut
        $amount = (int) round($order->getGrandTotal() * 100); // Convert to cents
        $checkoutData = [
            'amount' => $amount,
            'currency' => $order->getOrderCurrencyCode(),
            'mode' => 'payment',
            'ui_mode' => 'hosted', // Required field per Paypercut docs
            'locale' => $this->getLocaleForStore($order->getStoreId()),
            'success_url' => $this->urlBuilder->getUrl('paypercut/payment/success', [
                'order_id' => $order->getIncrementId()
            ]),
            'cancel_url' => $this->urlBuilder->getUrl('paypercut/payment/cancel', [
                'order_id' => $order->getIncrementId()
            ]),
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'amount' => $amount,
                'capture_method' => 'automatic'
            ],
            'metadata' => [
                'order_id'                     => $order->getIncrementId(),
                'order_entity_id'              => $order->getId(),
                'platform'                     => 'magento2',
                'platform_version'             => $this->productMetadata->getVersion(),
                'plugin_version'               => self::PLUGIN_VERSION,
                'php_version'                  => PHP_VERSION,
                'site_url'                     => $this->urlBuilder->getBaseUrl(),
                'paypercut_checkout_mode'      => 'hosted',
                'paypercut_checkout_operation' => 'payment',
            ]
        ];

        // Add customer info if available
        if ($order->getCustomerEmail()) {
            $checkoutData['customer_email'] = $order->getCustomerEmail();
        }

        $this->logger->info('Paypercut: Calling createCheckout API', ['data' => $checkoutData]);

        return $this->paypercutClient->createCheckout($checkoutData);
    }

    /**
     * Process BNPL (Buy Now Pay Later) payment
     * Uses endpoint: POST /v1/bnpl/attempt
     * @see https://docs.paypercut.io/bnpl-api-reference#tag/purchasebnplsvc/post/v1/bnpl/attempt
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @return array
     * @throws \Exception
     */
    private function processBnplPayment($order, $payment): array
    {
        $this->logger->info('Paypercut: Processing BNPL payment');

        $billingAddress = $order->getBillingAddress();

        // Build shopping cart items
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'title' => $item->getName(),
                'image_url' => '',
                'item_url' => '',
                'sku' => $item->getSku(),
                'qty' => (int) $item->getQtyOrdered(),
                'unit_price' => (int) round($item->getPriceInclTax() * 100) // Convert to cents
            ];
        }

        // Build BNPL attempt request matching API spec
        $bnplData = [
            'merchant_purchase_ref' => $order->getIncrementId(),
            'customer_redirect' => [
                'approved_url' => $this->urlBuilder->getUrl('paypercut/payment/success', [
                    'order_id' => $order->getIncrementId()
                ]),
                'canceled_url' => $this->urlBuilder->getUrl('paypercut/payment/cancel', [
                    'order_id' => $order->getIncrementId()
                ])
            ],
            'purchase_details' => [
                'billing_address' => [
                    'person' => [
                        'first_name' => $billingAddress->getFirstname(),
                        'last_name' => $billingAddress->getLastname(),
                        'phone' => $billingAddress->getTelephone() ?: '',
                        'email' => $order->getCustomerEmail()
                    ],
                    'address' => [
                        'country_code' => $billingAddress->getCountryId(),
                        'city' => $billingAddress->getCity(),
                        'postal_code' => $billingAddress->getPostcode() ?: '',
                        'street1' => is_array($billingAddress->getStreet()) ? ($billingAddress->getStreet()[0] ?? '') : '',
                        'street2' => is_array($billingAddress->getStreet()) ? ($billingAddress->getStreet()[1] ?? '') : ''
                    ]
                ],
                'customer' => [
                    'customer_ref' => $order->getCustomerId() ? (string) $order->getCustomerId() : $order->getCustomerEmail()
                ],
                'shopping_card' => [
                    'currency_code' => $order->getOrderCurrencyCode(),
                    'total_amount' => (int) round($order->getGrandTotal() * 100),
                    'items' => $items
                ]
            ]
        ];

        $this->logger->info('Paypercut: Calling createBnplAttempt API', [
            'order_id' => $order->getIncrementId(),
            'total_amount' => $bnplData['purchase_details']['shopping_card']['total_amount'],
            'currency' => $bnplData['purchase_details']['shopping_card']['currency_code']
        ]);

        return $this->paypercutClient->createBnplAttempt($bnplData);
    }

    /**
     * Get Paypercut locale based on store configuration
     *
     * @param int $storeId
     * @return string
     */
    private function getLocaleForStore($storeId): string
    {
        // Get locale from store configuration
        $magentoLocale = $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Map Magento locale to Paypercut locale format
        // Paypercut supports locales like: bg, bg-BG, ro, ro-RO, en, en-GB, etc.
        $locale = $this->mapToPaypercutLocale($magentoLocale);

        $this->logger->info('Paypercut: Using locale for checkout', [
            'store_id' => $storeId,
            'magento_locale' => $magentoLocale,
            'paypercut_locale' => $locale
        ]);

        return $locale;
    }

    /**
     * Map Magento locale to Paypercut locale format
     *
     * @param string|null $magentoLocale
     * @return string
     */
    private function mapToPaypercutLocale($magentoLocale): string
    {
        if (!$magentoLocale) {
            return 'auto'; // Let Paypercut auto-detect
        }

        // Magento locales are in format like: en_US, ro_RO, bg_BG
        // Paypercut expects: en-US, ro-RO, bg-BG
        $locale = str_replace('_', '-', $magentoLocale);

        // Map of supported Paypercut locales
        $supportedLocales = [
            'bg', 'bg-BG',
            'cs', 'cs-CZ',
            'da', 'da-DK',
            'de', 'de-DE',
            'el', 'el-GR',
            'en', 'en-GB',
            'es', 'es-ES',
            'et', 'et-EE',
            'fi', 'fi-FI',
            'fr', 'fr-FR',
            'hr', 'hr-HR',
            'hu', 'hu-HU',
            'id', 'id-ID',
            'it', 'it-IT',
            'lt', 'lt-LT',
            'lv', 'lv-LV',
            'ms', 'ms-MY',
            'mt', 'mt-MT',
            'nb', 'nb-NO',
            'nl', 'nl-NL',
            'pl', 'pl-PL',
            'pt', 'pt-PT',
            'ro', 'ro-RO',
            'sk', 'sk-SK',
            'sl', 'sl-SI',
            'sv', 'sv-SE',
            'th', 'th-TH',
            'tr', 'tr-TR'
        ];

        // Check if the full locale (e.g., ro-RO) is supported
        if (in_array($locale, $supportedLocales)) {
            return $locale;
        }

        // Check if just the language code (e.g., ro) is supported
        $languageCode = explode('-', $locale)[0];
        if (in_array($languageCode, $supportedLocales)) {
            return $languageCode;
        }

        // Fallback to auto if locale is not supported
        return 'auto';
    }
}
