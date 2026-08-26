<?php
namespace Paypercut\Payment\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Paypercut\Payment\Model\Support\Environment;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventRecorder;
use Paypercut\Payment\Model\Telemetry\TelemetrySession;
use Psr\Log\LoggerInterface;

class Client
{
    const CONFIG_PATH_ENVIRONMENT = Environment::CONFIG_PATH_ENVIRONMENT;
    const CONFIG_PATH_SECRET_KEY = 'payment/paypercut_card/secret_key';
    const CONFIG_PATH_DEBUG = 'payment/paypercut_card/debug';
    const CONFIG_PATH_BNPL_SECRET_KEY = 'payment/paypercut_bnpl/secret_key';
    const CONFIG_PATH_SUBSCRIPTION_ACTIVE = 'payment/paypercut_subscription/active';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var EventRecorder
     */
    private $recorder;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param Curl $curl
     * @param LoggerInterface $logger
     * @param Environment $environment
     * @param EventRecorder $recorder
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        Curl $curl,
        LoggerInterface $logger,
        Environment $environment,
        EventRecorder $recorder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->recorder = $recorder;
    }

    /**
     * Create checkout session
     *
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function createCheckout(array $data): array
    {
        $endpoint = $this->getApiUrl() . '/checkouts';
        
        $this->logger->info('Paypercut: Creating checkout session', [
            'endpoint' => $endpoint,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null
        ]);
        
        return $this->request('POST', $endpoint, $data, 'create_checkout');
    }

    /**
     * Get checkout session status
     *
     * @param string $checkoutId
     * @return array
     * @throws \Exception
     */
    public function getCheckout(string $checkoutId): array
    {
        $endpoint = $this->getApiUrl() . '/checkouts/' . $checkoutId;
        
        return $this->request('GET', $endpoint, null, 'get_checkout');
    }

    /**
     * Create BNPL (Buy Now Pay Later) attempt
     * Uses endpoint: POST /v1/bnpl/attempt
     * @see https://docs.paypercut.io/bnpl-api-reference#tag/purchasebnplsvc/post/v1/bnpl/attempt
     *
     * @param array $data Expected structure:
     *   - merchant_purchase_ref: string
     *   - customer_redirect: array (approved_url, canceled_url)
     *   - purchase_details: array (billing_address, customer, shopping_card)
     * @return array
     * @throws \Exception
     */
    public function createBnplAttempt(array $data): array
    {
        $endpoint = $this->getBnplApiUrl() . '/bnpl/attempt';
        
        $this->logger->info('Paypercut: Creating BNPL attempt', [
            'endpoint' => $endpoint,
            'merchant_purchase_ref' => $data['merchant_purchase_ref'] ?? null,
            'total_amount' => $data['purchase_details']['shopping_card']['total_amount'] ?? null
        ]);
        
        return $this->requestBnpl('POST', $endpoint, $data, 'create_bnpl_attempt');
    }

    /**
     * Get BNPL attempt details
     *
     * @param string $attemptId
     * @return array
     * @throws \Exception
     */
    public function getBnplAttempt(string $attemptId): array
    {
        $endpoint = $this->getBnplApiUrl() . '/bnpl/attempt/' . $attemptId;

        return $this->requestBnpl('GET', $endpoint, null, 'get_bnpl_attempt');
    }

    /**
     * Get BNPL attempt purchase status
     * Uses endpoint: GET /v1/bnpl/attempt/{attempt_id}/status
     * @see https://docs.paypercut.io/bnpl-api-reference#tag/purchasebnplsvc/get/v1/bnpl/attempt/{attempt_id}/status
     *
     * @param string $attemptId
     * @return array Response structure:
     *   - attempt: array (attempt_id, purchase_id, merchant_purchase_ref, provider_purchase_ref, redirect_url, provider_name, status, status_reason)
     *   - created_at: string (ISO 8601 datetime)
     * @throws \Exception
     */
    public function getBnplAttemptStatus(string $attemptId): array
    {
        $endpoint = $this->getBnplApiUrl() . '/bnpl/attempt/' . $attemptId . '/status';

        $this->logger->info('Paypercut: Checking BNPL attempt status', [
            'attempt_id' => $attemptId,
            'endpoint' => $endpoint
        ]);

        return $this->requestBnpl('GET', $endpoint, null, 'get_bnpl_attempt_status');
    }

    /**
     * Create refund
     *
     * @param array $data Expected structure:
     *   - amount: int (amount in smallest currency unit, e.g., cents)
     *   - payment: string (payment ID)
     *   - payment_intent: string (payment intent ID)
     *   - reason: string (reason for refund, e.g., 'duplicate', 'requested_by_customer', 'fraudulent')
     * @return array
     * @throws \Exception
     */
    public function createRefund(array $data): array
    {
        $endpoint = $this->getApiUrl() . '/refunds';
        
        $this->logger->info('Paypercut: Creating refund', [
            'endpoint' => $endpoint,
            'amount' => $data['amount'] ?? null,
            'payment' => $data['payment'] ?? null,
            'reason' => $data['reason'] ?? null
        ]);
        
        return $this->request('POST', $endpoint, $data, 'create_refund');
    }

    /**
     * Make API request for standard payments
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param string $context
     * @return array
     * @throws \Exception
     */
    private function request(string $method, string $endpoint, ?array $data = null, string $context = ''): array
    {
        $secretKey = $this->getSecretKey();
        
        if (empty($secretKey)) {
            $this->logger->error('Paypercut: Secret key is not configured');
            $this->recorder->record(
                Event::failure('api.request_failed', 'no_api_secret', ['api_context' => $context])
            );
            throw new \Exception('Paypercut secret key is not configured');
        }

        return $this->doRequest($method, $endpoint, $data, $secretKey, $context);
    }

    /**
     * Make API request for BNPL
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param string $context
     * @return array
     * @throws \Exception
     */
    private function requestBnpl(string $method, string $endpoint, ?array $data = null, string $context = ''): array
    {
        $secretKey = $this->getBnplSecretKey();
        
        if (empty($secretKey)) {
            $this->logger->error('Paypercut: BNPL secret key is not configured');
            $this->recorder->record(
                Event::failure('api.request_failed', 'no_api_secret', ['api_context' => $context])
            );
            throw new \Exception('Paypercut BNPL secret key is not configured');
        }

        return $this->doRequest($method, $endpoint, $data, $secretKey, $context);
    }

    /**
     * Execute the actual API request
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param string $secretKey
     * @param string $context
     * @return array
     * @throws \Exception
     */
    private function doRequest(string $method, string $endpoint, ?array $data, string $secretKey, string $context = ''): array
    {
        $this->logger->info('Paypercut API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'has_secret_key' => !empty($secretKey),
            'data' => $data
        ]);

        $started = microtime(true);

        try {
            // Set headers exactly as in working Postman request
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $secretKey
            ]);

            // Set cURL options to match working Postman configuration
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_ENCODING, '');
            $this->curl->setOption(CURLOPT_MAXREDIRS, 10);
            $this->curl->setOption(CURLOPT_TIMEOUT, 0);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

            if ($method === 'POST') {
                $jsonData = json_encode($data);
                $this->logger->info('Paypercut: Sending POST request', ['json' => $jsonData]);
                
                // Use CUSTOMREQUEST with POSTFIELDS like Postman
                $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'POST');
                $this->curl->setOption(CURLOPT_POSTFIELDS, $jsonData);
                $this->curl->get($endpoint);
            } elseif ($method === 'PATCH') {
                $jsonData = json_encode($data);
                $this->logger->info('Paypercut: Sending PATCH request', ['json' => $jsonData]);
                $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PATCH');
                $this->curl->setOption(CURLOPT_POSTFIELDS, $jsonData);
                $this->curl->get($endpoint);
            } elseif ($method === 'DELETE') {
                $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
                $this->curl->get($endpoint);
            } else {
                $this->curl->get($endpoint);
            }

            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->info('Paypercut API Response', [
                'status' => $statusCode,
                'response' => $response
            ]);

            $result = json_decode($response, true);
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if ($statusCode < 200 || $statusCode >= 300) {
                // Log full error details for debugging
                $this->logger->error('Paypercut API Error - Full Details', [
                    'http_status' => $statusCode,
                    'endpoint' => $endpoint,
                    'request_data' => $data,
                    'raw_response' => $response,
                    'parsed_response' => $result,
                    'error_message' => $result['message'] ?? null,
                    'error_code' => $result['code'] ?? $result['error_code'] ?? null,
                    'error_details' => $result['details'] ?? $result['errors'] ?? $result['error'] ?? null,
                ]);

                $errorMessage = $result['message']
                    ?? $result['error']['message']
                    ?? $result['detail']
                    ?? $response
                    ?: 'Unknown error (HTTP ' . $statusCode . ')';

                throw $this->rejected(
                    'Paypercut API error: ' . $errorMessage,
                    $statusCode,
                    is_array($result) ? $result : null,
                    $context,
                    $durationMs
                );
            }

            if (!is_array($result) && trim((string) $response) !== '') {
                // Byte count only: the body is the one thing not to report.
                $this->recorder->record(
                    Event::failure('api.response_unparsable', 'decode_failed', [
                        'api_context' => $context,
                        'body_bytes' => strlen((string) $response)
                    ])
                );
            }

            // Only slow calls are timed as events. Timing every call would fill
            // the queue with the requests nobody is investigating.
            if ($durationMs >= TelemetrySession::SLOW_REQUEST_MS) {
                $this->recorder->record(
                    Event::of('api.request_slow', [
                        'api_context' => $context,
                        'method' => $method,
                        'duration_ms' => $durationMs
                    ])
                );
            }

            return $result ?: [];

        } catch (PaypercutApiException $e) {
            $this->logger->error('Paypercut API Exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Paypercut API Exception', [
                'message' => $e->getMessage(),
                'endpoint' => $endpoint
            ]);

            $this->recordTransportFailure($e, $context, (int) round((microtime(true) - $started) * 1000));

            throw $e;
        }
    }

    /**
     * Build the exception for a rejected request, and report it.
     *
     * A body that parsed is a structured platform error and carries a code, a
     * param and a trace id; one that did not is reported as such, because the
     * difference between "Paypercut refused this" and "something in front of
     * Paypercut answered" is the whole diagnosis.
     *
     * @param string $message
     * @param int $statusCode
     * @param array|null $body
     * @param string $context
     * @param int $durationMs
     * @return PaypercutApiException
     */
    private function rejected(
        string $message,
        int $statusCode,
        ?array $body,
        string $context,
        int $durationMs
    ): PaypercutApiException {
        $exception = PaypercutApiException::fromBody($message, $statusCode, $body ?? []);

        $this->recorder->record(
            $body !== null
                ? Event::apiFailure('api.request_failed', $exception, [
                    'api_context' => $context,
                    'duration_ms' => $durationMs
                ])
                : Event::failure('api.request_failed', 'http_' . $statusCode, [
                    'api_context' => $context,
                    'http_status' => $statusCode,
                    'body_parsable' => false,
                    'duration_ms' => $durationMs
                ])
        );

        return $exception;
    }

    /**
     * Report a request that never got an answer.
     *
     * A connect failure that took the full timeout is a network black hole; one
     * that returned at once is DNS or a refused port — which is why the
     * duration travels with every one of these.
     *
     * @param \Exception $exception
     * @param string $context
     * @param int $durationMs
     * @return void
     */
    private function recordTransportFailure(\Exception $exception, string $context, int $durationMs): void
    {
        // Magento's cURL wrapper collapses every libcurl failure into one
        // exception, so the reason is recovered from its message.
        $connectFailure = (bool) preg_match(
            '/could not resolve|failed to connect|connection refused|connect\(\) timed out|ssl connect error/i',
            $exception->getMessage()
        );

        $this->recorder->record(
            Event::failure(
                'api.request_failed',
                $connectFailure ? 'connect' : 'transport',
                [
                    'api_context' => $context,
                    'duration_ms' => $durationMs
                ],
                $exception
            )
        );
    }

    /**
     * Get secret key (decrypted)
     *
     * @return string|null
     */
    private function getSecretKey(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PATH_SECRET_KEY,
            ScopeInterface::SCOPE_STORE
        );
        
        return $value ?: null;
    }

    /**
     * Get BNPL secret key (decrypted)
     *
     * @return string|null
     */
    private function getBnplSecretKey(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PATH_BNPL_SECRET_KEY,
            ScopeInterface::SCOPE_STORE
        );
        
        return $value ?: null;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    private function isDebugEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get standard API URL based on environment config
     *
     * @return string
     */
    private function getApiUrl(): string
    {
        return $this->environment->getApiBaseUri() . 'v1';
    }

    /**
     * Get BNPL API URL based on environment config
     *
     * @return string
     */
    private function getBnplApiUrl(): string
    {
        return $this->environment->getApiBaseUri() . 'bnpl/v1';
    }

    /**
     * Create a subscription
     *
     * @param array $data Subscription data
     * @return array
     * @throws \Exception
     */
    public function createSubscription(array $data): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions';
        
        $this->logger->info('Paypercut: Creating subscription', [
            'endpoint' => $endpoint,
            'customer' => $data['customer'] ?? null,
            'items_count' => count($data['items'] ?? [])
        ]);
        
        return $this->request('POST', $endpoint, $data, 'create_subscription');
    }

    /**
     * Get subscription details
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function getSubscription(string $subscriptionId): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions/' . $subscriptionId;
        
        return $this->request('GET', $endpoint, null, 'get_subscription');
    }

    /**
     * Update a subscription
     *
     * @param string $subscriptionId
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function updateSubscription(string $subscriptionId, array $data): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions/' . $subscriptionId;
        
        $this->logger->info('Paypercut: Updating subscription', [
            'subscription_id' => $subscriptionId,
            'data' => $data
        ]);
        
        return $this->doRequest('PATCH', $endpoint, $data, $this->getSecretKey(), 'update_subscription');
    }

    /**
     * Cancel a subscription
     *
     * @param string $subscriptionId
     * @param array $cancellationDetails Optional cancellation details
     * @return array
     * @throws \Exception
     */
    public function cancelSubscription(string $subscriptionId, array $cancellationDetails = []): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions/' . $subscriptionId . '/cancel';
        
        $this->logger->info('Paypercut: Cancelling subscription', [
            'subscription_id' => $subscriptionId,
            'details' => $cancellationDetails
        ]);
        
        return $this->request('POST', $endpoint, $cancellationDetails, 'cancel_subscription');
    }

    /**
     * Pause a subscription
     *
     * @param string $subscriptionId
     * @param array $pauseData Optional pause configuration
     * @return array
     * @throws \Exception
     */
    public function pauseSubscription(string $subscriptionId, array $pauseData = []): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions/' . $subscriptionId . '/pause';
        
        $this->logger->info('Paypercut: Pausing subscription', [
            'subscription_id' => $subscriptionId
        ]);
        
        return $this->request('POST', $endpoint, $pauseData, 'pause_subscription');
    }

    /**
     * Resume a paused subscription
     *
     * @param string $subscriptionId
     * @return array
     * @throws \Exception
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        $endpoint = $this->getApiUrl() . '/subscriptions/' . $subscriptionId . '/resume';
        
        $this->logger->info('Paypercut: Resuming subscription', [
            'subscription_id' => $subscriptionId
        ]);
        
        return $this->request('POST', $endpoint, null, 'resume_subscription');
    }

    /**
     * List customer subscriptions
     *
     * @param string $customerId
     * @param array $params Optional filters (status, limit, starting_after)
     * @return array
     * @throws \Exception
     */
    public function listSubscriptions(string $customerId, array $params = []): array
    {
        $queryString = http_build_query(array_merge(['customer' => $customerId], $params));
        $endpoint = $this->getApiUrl() . '/subscriptions?' . $queryString;
        
        return $this->request('GET', $endpoint, null, 'list_subscriptions');
    }

    /**
     * Create or retrieve a Paypercut customer
     *
     * @param array $customerData
     * @return array
     * @throws \Exception
     */
    public function createCustomer(array $customerData): array
    {
        $endpoint = $this->getApiUrl() . '/customers';
        
        $this->logger->info('Paypercut: Creating customer', [
            'email' => $customerData['email'] ?? null
        ]);
        
        return $this->request('POST', $endpoint, $customerData, 'create_customer');
    }

    /**
     * Get customer details
     *
     * @param string $customerId
     * @return array
     * @throws \Exception
     */
    public function getCustomer(string $customerId): array
    {
        $endpoint = $this->getApiUrl() . '/customers/' . $customerId;
        
        return $this->request('GET', $endpoint, null, 'get_customer');
    }

    /**
     * Get payment intent details
     *
     * @deprecated This endpoint doesn't exist in Paypercut API (returns 404)
     * @param string $paymentIntentId
     * @return array
     * @throws \Exception
     */
    public function getPaymentIntent(string $paymentIntentId): array
    {
        throw new \Exception('getPaymentIntent is not supported by Paypercut API');
    }

    /**
     * Create a payment method
     * Uses endpoint: POST /v1/payment_methods
     * @see https://docs.paypercut.io/api-reference#tag/payment-methods/post/v1/payment_methods
     *
     * @deprecated This method is not needed - payment methods are created by Paypercut
     *             during the hosted checkout flow and can be retrieved via payment_intent
     * @param string $customerId
     * @param string $checkoutId
     * @return array
     * @throws \Exception
     */
    public function createPaymentMethod(string $customerId, string $checkoutId): array
    {
        $endpoint = $this->getApiUrl() . '/payment_methods';
        
        $data = [
            'customer' => $customerId,
            'checkout' => $checkoutId,
            'type' => 'card'
        ];
        
        $this->logger->info('Paypercut: Creating payment method', [
            'customer_id' => $customerId,
            'checkout_id' => $checkoutId
        ]);
        
        return $this->request('POST', $endpoint, $data, 'create_payment_method');
    }
}

