<?php
namespace Paypercut\Payment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Class TransactionClient
 * HTTP Client for Paypercut Payment API
 */
class TransactionClient implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    const SANDBOX_URL = 'https://sandbox-api.paypercut.io/v1';
    const PRODUCTION_URL = 'https://api.paypercut.io/v1';

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @param Logger $logger
     * @param ConfigInterface $config
     * @param Curl $curl
     */
    public function __construct(
        Logger $logger,
        ConfigInterface $config,
        Curl $curl
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->curl = $curl;
    }

    /**
     * Places request to gateway
     *
     * @param TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $request = $transferObject->getBody();

        $this->logger->debug([
            'request' => $request,
            'client' => static::class
        ]);

        try {
            $response = $this->processRequest($request);
        } catch (\Exception $e) {
            $this->logger->debug(['exception' => $e->getMessage()]);
            $response = [
                'RESULT_CODE' => self::FAILURE,
                'ERROR_MESSAGE' => $e->getMessage()
            ];
        }

        $this->logger->debug(['response' => $response]);

        return $response;
    }

    /**
     * Process the request and return response
     *
     * @param array $request
     * @return array
     */
    private function processRequest(array $request)
    {
        $txnType = $request['TXN_TYPE'] ?? 'A';
        $endpoint = $this->getEndpoint($txnType);
        $method = $this->getHttpMethod($txnType);
        $apiUrl = $this->getApiUrl() . $endpoint;

        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . ($request['MERCHANT_KEY'] ?? ''),
            'X-API-Version' => '1.0'
        ];

        // Remove internal fields before sending
        unset($request['TXN_TYPE'], $request['MERCHANT_KEY']);

        $this->curl->setHeaders($headers);

        if ($method === 'POST') {
            $this->curl->post($apiUrl, json_encode($request));
        } elseif ($method === 'PUT') {
            $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
            $this->curl->post($apiUrl, json_encode($request));
        } else {
            $this->curl->get($apiUrl);
        }

        $responseBody = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        $this->logger->debug([
            'api_url' => $apiUrl,
            'method' => $method,
            'status_code' => $statusCode,
            'response_body' => $responseBody
        ]);

        // Parse response
        if ($statusCode >= 200 && $statusCode < 300) {
            $responseData = json_decode($responseBody, true) ?: [];
            return array_merge([
                'RESULT_CODE' => self::SUCCESS,
                'TXN_ID' => $responseData['transaction_id'] ?? ('PAYPERCUT_' . uniqid())
            ], $this->mapResponseData($responseData));
        }

        $errorData = json_decode($responseBody, true) ?: [];
        return [
            'RESULT_CODE' => self::FAILURE,
            'ERROR_MESSAGE' => $errorData['message'] ?? ('Request failed with status: ' . $statusCode)
        ];
    }

    /**
     * Map gateway response to internal format
     *
     * @param array $responseData
     * @return array
     */
    private function mapResponseData(array $responseData)
    {
        $mapped = [];

        // Map common fields
        if (isset($responseData['redirect_url'])) {
            $mapped['REDIRECT_URL'] = $responseData['redirect_url'];
        }

        // Map vault token if present
        if (isset($responseData['card_token'])) {
            $mapped['VAULT_TOKEN'] = $responseData['card_token'];
            $mapped['CARD_TYPE'] = $responseData['card_type'] ?? 'VI';
            $mapped['MASKED_CARD'] = $responseData['masked_card'] ?? '****';
            $mapped['CARD_EXPIRY'] = $responseData['card_expiry'] ?? '';
        }

        return $mapped;
    }

    /**
     * Get API endpoint based on transaction type
     *
     * @param string $txnType
     * @return string
     */
    private function getEndpoint($txnType)
    {
        $endpoints = [
            'A' => '/transactions/authorize',
            'C' => '/transactions/capture',
            'V' => '/transactions/void',
            'R' => '/transactions/refund'
        ];

        return $endpoints[$txnType] ?? '/transactions';
    }

    /**
     * Get HTTP method based on transaction type
     *
     * @param string $txnType
     * @return string
     */
    private function getHttpMethod($txnType)
    {
        return 'POST'; // All transaction types use POST
    }

    /**
     * Get API URL based on environment
     *
     * @return string
     */
    private function getApiUrl()
    {
        $environment = $this->config->getValue('environment');
        return $environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }
}
