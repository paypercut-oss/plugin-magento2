<?php
/**
 * Copyright (C) Blugento S.A. - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Marius Boia <marius.boia@blugento.ro>, Jan 2026
 */

namespace Paypercut\Payment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Paypercut\Payment\Model\Api\Client as PaypercutClient;
use Paypercut\Payment\Model\Api\PaypercutApiException;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Psr\Log\LoggerInterface;

class Form extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Which of the two hosted-checkout entry points this store used.
     */
    private const SOURCE = 'payment_form';

    /**
     * @var \Magento\Sales\Model\Order|null
     */
    protected $order;

    /**
     * @var array
     */
    protected $urls;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var PaypercutClient
     */
    protected $paypercutClient;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var EventRecorder
     */
    protected $recorder;

    /**
     * Form constructor.
     *
     * @param UrlInterface $urlBuilder
     * @param PaypercutClient $paypercutClient
     * @param OrderRepositoryInterface $orderRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param EventRecorder $recorder
     */
    public function __construct(
        UrlInterface $urlBuilder,
        PaypercutClient $paypercutClient,
        OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EventRecorder $recorder
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->paypercutClient = $paypercutClient;
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->recorder = $recorder;
    }

    /**
     * Set order
     *
     * @param \Magento\Sales\Model\Order $order
     */
    public function setOrder(\Magento\Sales\Model\Order $order)
    {
        $this->order = $order;
    }

    /**
     * Set success and cancel url
     *
     * @param array $urls
     */
    public function setUrls($urls)
    {
        $this->urls = $urls;
    }

    /**
     * Return payment form data
     *
     * @return string
     * @throws LocalizedException
     */
    public function getForm()
    {
        if (!$this->order) {
            throw new LocalizedException(__('Order is required'));
        }

        try {
            $payment = $this->order->getPayment();
            $paymentMethod = $payment->getMethod();

            $this->logger->info('Paypercut Form: Processing order', [
                'order_id' => $this->order->getIncrementId(),
                'payment_method' => $paymentMethod
            ]);

            // Check if this is BNPL or standard card payment
            try {
                if ($paymentMethod === 'paypercut_bnpl') {
                    $response = $this->processBnplPayment($this->order);
                    $redirectUrl = $response['redirect_url'] ?? null;
                    $paypercutId = $response['attempt_id'] ?? null;
                } else {
                    $response = $this->processCardPayment($this->order);
                    $redirectUrl = $response['url'] ?? null;
                    $paypercutId = $response['id'] ?? null;
                }
            } catch (\Exception $e) {
                $this->recordCreateFailed($e, (string) $paymentMethod, (string) $this->order->getIncrementId());
                throw $e;
            }

            if (!$redirectUrl) {
                $this->logger->error('Paypercut Form: Missing URL in response', ['response' => $response]);
                $this->recorder->record(
                    Event::failure('checkout.hosted.redirect_missing', 'redirect_absent', [
                        'source' => self::SOURCE,
                        'method' => (string) $paymentMethod
                    ])->about(['order_ref' => (string) $this->order->getIncrementId()])
                );
                throw new LocalizedException(__('Invalid response from Paypercut API: missing redirect URL'));
            }
            
            // Save payment data to order for subscriptions and tracking
            $payment = $this->order->getPayment();
            $payment->setAdditionalInformation('paypercut_id', $paypercutId);
            $payment->setAdditionalInformation('redirect_url', $redirectUrl);
            
            // Save payment_intent ID if available (useful for tracking)
            if (!empty($response['payment_intent'])) {
                $payment->setAdditionalInformation('paypercut_payment_intent', $response['payment_intent']);
                
                $this->logger->info('Paypercut Form: Payment intent ID saved', [
                    'order_id' => $this->order->getIncrementId(),
                    'payment_intent' => $response['payment_intent']
                ]);
            }
            
            // Note: payment_method ID will be saved by IPN after customer completes payment
            
            $this->orderRepository->save($this->order);

            $data = json_encode([
                'action' => $redirectUrl,
                'data' => [],
                'method' => 'GET' // PayPerCut uses direct redirect, not POST form
            ]);

            $this->recorder->record(
                Event::of('checkout.hosted.redirected', [
                    'source' => self::SOURCE,
                    'method' => (string) $paymentMethod,
                    'order_status' => (string) $this->order->getStatus()
                ])->about([
                    'order_ref' => (string) $this->order->getIncrementId(),
                    'payment_id' => (string) $paypercutId,
                    'payment_intent_id' => (string) ($response['payment_intent'] ?? '')
                ])
            );

            $this->logger->info('Paypercut Form: Redirect data prepared', [
                'order_id' => $this->order->getIncrementId(),
                'redirect_url' => $redirectUrl,
                'paypercut_id' => $paypercutId
            ]);

            return $data;

        } catch (\Exception $e) {
            $this->logger->error('Paypercut Form error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new LocalizedException(__('Payment processing error: %1', $e->getMessage()));
        }
    }

    /**
     * Report a checkout session that could not be created.
     *
     * @param \Exception $exception
     * @param string $paymentMethod
     * @param string $orderRef
     * @return void
     */
    private function recordCreateFailed(\Exception $exception, string $paymentMethod, string $orderRef): void
    {
        $attrs = [
            'source' => self::SOURCE,
            'method' => $paymentMethod
        ];

        $event = $exception instanceof PaypercutApiException
            ? Event::apiFailure('checkout.hosted.create_failed', $exception, $attrs)
            : Event::failure('checkout.hosted.create_failed', 'session_create', $attrs, $exception);

        $this->recorder->record($event->about(['order_ref' => $orderRef]));
    }

    /**
     * Process standard card payment
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     * @throws \Exception
     */
    private function processCardPayment($order)
    {
        $this->logger->info('Paypercut Form: Processing CARD payment');

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
            'success_url' => $this->urls['success'] ?? $this->urlBuilder->getUrl('paypercut/payment/success', [
                'order_id' => $order->getIncrementId()
            ]),
            'cancel_url' => $this->urls['cancel'] ?? $this->urlBuilder->getUrl('paypercut/payment/cancel', [
                'order_id' => $order->getIncrementId()
            ]),
            'line_items' => $lineItems,
            'payment_intent_data' => [
                'amount' => $amount,
                'capture_method' => 'automatic'
            ],
            'metadata' => [
                'order_id' => $order->getIncrementId(),
                'order_entity_id' => $order->getId()
            ]
        ];

        // Add customer info if available
        if ($order->getCustomerEmail()) {
            $checkoutData['customer_email'] = $order->getCustomerEmail();
        }

        $this->logger->info('Paypercut Form: Calling createCheckout API', ['data' => $checkoutData]);

        return $this->paypercutClient->createCheckout($checkoutData);
    }

    /**
     * Process BNPL (Buy Now Pay Later) payment
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     * @throws \Exception
     */
    private function processBnplPayment($order)
    {
        $this->logger->info('Paypercut Form: Processing BNPL payment');

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

        // Build BNPL attempt request
        $bnplData = [
            'merchant_purchase_ref' => $order->getIncrementId(),
            'customer_redirect' => [
                'approved_url' => $this->urls['success'] ?? $this->urlBuilder->getUrl('paypercut/payment/success', [
                    'order_id' => $order->getIncrementId()
                ]),
                'canceled_url' => $this->urls['cancel'] ?? $this->urlBuilder->getUrl('paypercut/payment/cancel', [
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

        $this->logger->info('Paypercut Form: Calling createBnplAttempt API', [
            'order_id' => $order->getIncrementId(),
            'total_amount' => $bnplData['purchase_details']['shopping_card']['total_amount']
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

        $this->logger->info('Paypercut Form: Using locale for checkout', [
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
