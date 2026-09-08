<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

/**
 * Maps a telemetry mint rejection onto merchant-facing copy.
 *
 * Branches on the HTTP status first and consults the body only to refine copy:
 * the mint surfaces its gates as bare gRPC statuses with no public-error
 * metadata, so `telemetry_token_key_inactive` and friends arrive capitalized in
 * `message` with no `code` key at all — hence substring matching.
 */
class MintErrorMapper
{
    const NETWORK_ERROR = 'network_error';

    /**
     * @param int $status HTTP status, or 0 for a transport failure.
     * @param array $body Decoded response body, empty when absent.
     * @return array
     */
    public function map(int $status, array $body = []): array
    {
        $detail = $this->detail($body);

        switch ($status) {
            case 0:
                return $this->result(
                    self::NETWORK_ERROR,
                    (string) __("Your server couldn't reach Paypercut to start the debug session. Check that outbound HTTPS requests are allowed by your host or firewall, then try again."),
                    true
                );

            case 401:
                return $this->result(
                    'key_invalid',
                    (string) __("Paypercut couldn't verify this store's API key, so the debug session was not started and nothing was sent. Check the Secret Key in API Credentials above, then try again."),
                    false
                );

            case 400:
                if (strpos($detail, 'ineligible') !== false) {
                    return $this->result(
                        'key_ineligible',
                        (string) __("This store's Paypercut API key isn't eligible for debug sessions yet — this usually means the key isn't fully activated on your Paypercut account. Nothing has been sent. Contact Paypercut support and quote your account name."),
                        false
                    );
                }

                return $this->result(
                    'request_rejected',
                    (string) __('Paypercut rejected the debug session request. Nothing has been sent. Contact Paypercut support if this keeps happening.'),
                    false
                );

            case 403:
                return $this->result(
                    'account_refused',
                    (string) __("This store's Paypercut account isn't allowed to start debug sessions. Contact Paypercut support."),
                    false
                );

            case 404:
                return $this->result(
                    'not_available',
                    (string) __("Debug sessions aren't available for this store's Paypercut environment yet. Nothing was sent."),
                    false
                );

            case 429:
                return $this->result(
                    'rate_limited',
                    (string) __('Too many attempts. Wait about a minute and try again.'),
                    true
                );

            case 503:
            case 504:
                return $this->result(
                    'temporarily_unavailable',
                    (string) __("Paypercut's debug service is temporarily unavailable. Please try again in a few minutes."),
                    true
                );
        }

        if ($status >= 500) {
            return $this->result(
                'service_error',
                (string) __("Paypercut couldn't issue a debug token. Please try again — if it keeps happening, contact support and quote the reference below."),
                true
            );
        }

        return $this->result(
            'unexpected_response',
            (string) __('Paypercut returned an unexpected response. The debug session was not started — please try again.'),
            true
        );
    }

    /**
     * Copy for a 200 whose payload cannot be used.
     *
     * @return array
     */
    public function badResponse(): array
    {
        return $this->result(
            'bad_response',
            (string) __('Paypercut returned an unexpected response. The debug session was not started — please try again.'),
            true
        );
    }

    /**
     * Copy for a store whose clock is too far from Paypercut's to trust.
     *
     * Deliberately not reported as a Paypercut failure: it is a local NTP
     * problem, and saying otherwise sends the merchant to the wrong place.
     *
     * @param int $skewSeconds
     * @return array
     */
    public function clockSkew(int $skewSeconds): array
    {
        $minutes = (int) round(abs($skewSeconds) / 60);

        return $this->result(
            'clock_skew',
            (string) __(
                "This server's clock appears to be out of sync with Paypercut (off by about %1 minutes), so a debug session can't be started. Ask your host to enable time synchronisation (NTP), then try again.",
                $minutes
            ),
            false
        );
    }

    /**
     * Lowercased haystack of the body fields worth matching against.
     *
     * @param array $body
     * @return string
     */
    private function detail(array $body): string
    {
        $parts = [];

        foreach (['code', 'message', 'error', 'reason'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                $parts[] = $body[$key];
            }
        }

        return strtolower(implode(' ', $parts));
    }

    /**
     * @param string $reasonCode
     * @param string $message
     * @param bool $retryable
     * @return array
     */
    private function result(string $reasonCode, string $message, bool $retryable): array
    {
        return [
            'reason_code' => $reasonCode,
            'message' => $message,
            'retryable' => $retryable,
        ];
    }
}
