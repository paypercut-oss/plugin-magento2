<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

/**
 * The raw HTTP the telemetry paths use, and nothing else.
 *
 * Deliberately NOT Magento\Framework\HTTP\Client\Curl and not the module's own
 * API client. The API client logs request and response bodies, which would
 * write the minted token into the store's log files, and it throws on the
 * shapes we need to branch on. The framework wrapper is a concrete class any
 * third-party module can attach a DI plugin to, which would make the store's
 * API key readable by that module on the mint request.
 *
 * Never throws on an HTTP status. A status of 0 means the request never
 * completed at all — DNS failure, refused connection, TLS failure, timeout or
 * a blocked host policy — which is distinct from every real HTTP status.
 */
class Http
{
    /**
     * POST a JSON body, or no body at all when $jsonBody is null.
     *
     * @param string $url
     * @param string[] $headers
     * @param string|null $jsonBody
     * @param int $timeoutSeconds
     * @param int $connectTimeoutSeconds
     * @return array
     */
    public function postJson(
        string $url,
        array $headers,
        ?string $jsonBody,
        int $timeoutSeconds,
        int $connectTimeoutSeconds
    ): array {
        $started = microtime(true);
        $handle = curl_init();

        if ($handle === false) {
            return $this->failure($started);
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_POSTFIELDS => $jsonBody ?? '',
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($body === false || $status === 0) {
            return $this->failure($started);
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string) $body,
            'duration_ms' => $this->elapsedMs($started),
        ];
    }

    /**
     * @param array $response
     * @param string $name
     * @return string
     */
    public static function header(array $response, string $name): string
    {
        $headers = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : [];

        return (string) ($headers[strtolower($name)] ?? '');
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    /**
     * @param float $started
     * @return array
     */
    private function failure(float $started): array
    {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'duration_ms' => $this->elapsedMs($started),
        ];
    }

    /**
     * @param float $started
     * @return int
     */
    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
