<?php
namespace Paypercut\Payment\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Paypercut\Payment\Model\Support\Environment;

/**
 * Class BnplClient
 * HTTP Client for Paypercut BNPL API
 * @see https://docs.paypercut.io/api-reference
 */
class BnplClient implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;
    const PENDING = 2;

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
     * @var Environment
     */
    private $environment;

    /**
     * @param Logger $logger
     * @param ConfigInterface $config
     * @param Curl $curl
     * @param Environment $environment
     */
    public function __construct(
        Logger $logger,
        ConfigInterface $config,
        Curl $curl,
        Environment $environment
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->curl = $curl;
        $this->environment = $environment;
    }

    /**
     * Places request to BNPL gateway
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
        $txnType = $request['TXN_TYPE'] ?? '';
        $endpoint = $this->getEndpoint($txnType, $request);
        $apiUrl = $this->getApiUrl() . $endpoint;

        // Prepare headers
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . ($request['MERCHANT_KEY'] ?? ''),
            'X-API-Version' => '1.0'
        ];

        // Remove internal fields before sending
        unset($request['TXN_TYPE'], $request['MERCHANT_KEY'], $request['TXN_ID']);

        $this->curl->setHeaders($headers);
        $this->curl->post($apiUrl, json_encode($request));

        $responseBody = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        $this->logger->debug([
            'api_url' => $apiUrl,
            'status_code' => $statusCode,
            'response_body' => $responseBody
        ]);

        if ($statusCode >= 200 && $statusCode < 300) {
            $responseData = json_decode($responseBody, true) ?: [];
            return array_merge([
                'RESULT_CODE' => self::SUCCESS,
                'TXN_ID' => $responseData['attempt_id'] ?? ('BNPL_' . uniqid()),
            ], $this->mapBnplResponseData($responseData));
        }

        $errorData = json_decode($responseBody, true) ?: [];
        return [
            'RESULT_CODE' => self::FAILURE,
            'ERROR_MESSAGE' => $errorData['message'] ?? ('BNPL request failed with status: ' . $statusCode)
        ];
    }

    /**
     * Map BNPL API response fields to internal gateway format
     *
     * @param array $responseData
     * @return array
     */
    private function mapBnplResponseData(array $responseData)
    {
        $mapped = [];

        if (isset($responseData['redirect_url'])) {
            $mapped['REDIRECT_URL'] = $responseData['redirect_url'];
        }

        if (isset($responseData['attempt_id'])) {
            $mapped['BNPL_ORDER_ID'] = $responseData['attempt_id'];
        }

        return $mapped;
    }

    /**
     * Get API endpoint based on transaction type
     *
     * @param string $txnType
     * @param array $request
     * @return string
     */
    private function getEndpoint($txnType, array $request = [])
    {
        $txnId = $request['TXN_ID'] ?? '';

        $endpoints = [
            'BNPL_AUTH' => '/bnpl/attempt',
            'BNPL_CAPTURE' => '/bnpl/attempt/' . $txnId . '/capture',
            'V' => '/bnpl/attempt/' . $txnId . '/cancel',
            'R' => '/bnpl/transaction/' . $txnId . '/refund',
        ];

        return $endpoints[$txnType] ?? '/bnpl/attempt';
    }

    /**
     * Get API URL based on environment
     *
     * @return string
     */
    private function getApiUrl()
    {
        return $this->environment->getApiBaseUri() . 'bnpl/v1';
    }
}
