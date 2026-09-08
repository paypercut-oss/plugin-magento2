<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Serialize\Serializer\Json;
use Paypercut\Payment\Model\Support\Environment;

/**
 * Exchanges the store's API key for a short-lived telemetry token.
 *
 * The store's long-lived secret travels on this request, so the destination is
 * re-validated here rather than trusted, and the request goes over the raw HTTP
 * client — never the module's logging API client.
 */
class TokenMinter
{
    const PATH = 'v1/telemetry/tokens';

    /**
     * @var Http
     */
    private $http;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Http $http
     * @param Json $json
     */
    public function __construct(Http $http, Json $json)
    {
        $this->http = $http;
        $this->json = $json;
    }

    /**
     * Request a telemetry token.
     *
     * @param string $secret The store's API secret key.
     * @param string $mintBase Base URI of the Payment Engine for this environment.
     * @return array
     */
    public function mint(string $secret, string $mintBase): array
    {
        if (Environment::allowedPaypercutBase($mintBase) === '') {
            return self::failure();
        }

        // The endpoint takes no request body at all; sending [] or {} is a
        // different request.
        $response = $this->http->postJson(
            rtrim($mintBase, '/') . '/' . self::PATH,
            [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
            ],
            null,
            TelemetrySession::MINT_TIMEOUT_SECONDS,
            TelemetrySession::MINT_CONNECT_TIMEOUT_SECONDS
        );

        $status = (int) $response['status'];

        if ($status === 0) {
            return self::failure();
        }

        $body = $this->decode((string) $response['body']);
        $headerTraceId = Http::header($response, 'Trace-Id');

        return [
            'status' => $status,
            'body' => $body,
            'token' => isset($body['token']) && is_string($body['token']) ? $body['token'] : '',
            'expires_at' => isset($body['expires_at']) && is_string($body['expires_at']) ? $body['expires_at'] : '',
            'date' => Http::header($response, 'Date'),
            // The gateway sets `Trace-Id`; on an error it also repeats it in
            // the body, which is the more reliable of the two.
            'trace_id' => $headerTraceId !== ''
                ? $headerTraceId
                : (isset($body['trace_id']) && is_string($body['trace_id']) ? $body['trace_id'] : ''),
            'request_id' => Http::header($response, 'X-Request-Id'),
        ];
    }

    /**
     * How long the token is good for, measured on the MINT's clock.
     *
     * `expires_at` is stamped by the mint; time() is this server's idea of now.
     * Stores routinely drift by minutes, so the two are not comparable: copying
     * the timestamp would either overrun the token (clock behind) or make Start
     * permanently impossible (clock ahead). Measuring the mint's own
     * `expires_at - Date` yields a duration, which is portable to any clock.
     *
     * @param string $expiresAt RFC3339 expiry from the response body.
     * @param string $dateHeader The response's Date header, '' when absent.
     * @param int $now This server's current unix timestamp.
     * @return int Lifetime in seconds; 0 when `expires_at` cannot be parsed.
     */
    public static function deriveLifetime(string $expiresAt, string $dateHeader, int $now): int
    {
        $expiry = strtotime($expiresAt);

        if ($expiry === false) {
            return 0;
        }

        $issued = $dateHeader !== '' ? strtotime($dateHeader) : false;

        if ($issued === false) {
            $issued = $now;
        }

        return (int) $expiry - (int) $issued;
    }

    /**
     * Signed difference between the mint's clock and this server's, in seconds.
     *
     * Logged on every successful mint so support can spot a drifting store
     * before it turns into an unexplainable failure.
     *
     * @param string $dateHeader
     * @param int $now
     * @return int
     */
    public static function skew(string $dateHeader, int $now): int
    {
        if ($dateHeader === '') {
            return 0;
        }

        $issued = strtotime($dateHeader);

        return $issued === false ? 0 : (int) $issued - $now;
    }

    /**
     * @param string $body
     * @return array
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array
     */
    private static function failure(): array
    {
        return [
            'status' => 0,
            'body' => [],
            'token' => '',
            'expires_at' => '',
            'date' => '',
            'trace_id' => '',
            'request_id' => '',
        ];
    }
}
