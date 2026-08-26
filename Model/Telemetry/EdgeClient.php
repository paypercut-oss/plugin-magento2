<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * Delivers a batch of diagnostic events to the public telemetry edge.
 *
 * The edge verifies the bearer token offline and never calls back into the
 * platform, so a request never blocks on the payment platform.
 *
 * The body is worth reading. A 202 carries {"accepted":N,"dropped":M} — the
 * only way a client learns the edge discarded part of a batch it accepted — and
 * a 413 carries the `limits` a batch must be split to satisfy.
 */
class EdgeClient
{
    const PATH = 'v1/telemetry';

    /**
     * The edge's own responses are a few dozen bytes; anything larger is not one.
     */
    const MAX_RESPONSE_BYTES = 4096;

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
     * POST one batch.
     *
     * @param string $edgeBase Base URI of the telemetry edge for this environment.
     * @param string $jwt The telemetry token.
     * @param string $jsonBody Serialized batch.
     * @return array Status 0 means the request never completed.
     */
    public function send(string $edgeBase, string $jwt, string $jsonBody): array
    {
        $response = $this->http->postJson(
            rtrim($edgeBase, '/') . '/' . self::PATH,
            [
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type' => 'application/json',
            ],
            $jsonBody,
            TelemetrySession::EDGE_TIMEOUT_SECONDS,
            TelemetrySession::EDGE_CONNECT_TIMEOUT_SECONDS
        );

        return [
            'status' => (int) $response['status'],
            'retry_after' => (int) Http::header($response, 'Retry-After'),
            'body' => $this->decode((string) $response['body']),
        ];
    }

    /**
     * Anything that is not a small JSON object is no answer at all.
     *
     * A 413 from a proxy in front of the edge is an HTML page, and a captive
     * portal will happily return 200 with a login form.
     *
     * @param string $body
     * @return array
     */
    private function decode(string $body): array
    {
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
