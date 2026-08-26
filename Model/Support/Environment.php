<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Support;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves every Paypercut host this module talks to from one stored environment.
 *
 * The payment API host and the telemetry edge host are derived from the same
 * `environment` value in the same call sequence. A telemetry token minted for
 * one environment is rejected by every other environment's edge with a 401 that
 * is indistinguishable from a forged token, so the two must never be resolved
 * from independent settings.
 */
class Environment
{
    const DEV = 'dev';
    const STAGE = 'stage';
    const PRODUCTION = 'production';

    /**
     * The value stores connected before this setting was reworked still hold.
     *
     * Sandbox and production always resolved to the same payment API host, so
     * it is production under an older name — named here rather than left to
     * fall through, so those stores keep one coherent pair of hosts instead of
     * a production payment API and no telemetry at all.
     */
    const LEGACY_SANDBOX = 'sandbox';

    const CONFIG_PATH_ENVIRONMENT = 'payment/paypercut_card/environment';

    const DEFAULT_API_BASE_URI = 'https://api.paypercut.io/';

    /**
     * Payment API base per environment.
     *
     * @var array<string, string>
     */
    private const API_BASE_URIS = [
        self::DEV => 'https://api.dev.paypercut.net/',
        self::STAGE => 'https://api.stage.paypercut.net/',
        self::PRODUCTION => self::DEFAULT_API_BASE_URI,
    ];

    /**
     * Telemetry edge base per environment.
     *
     * @var array<string, string>
     */
    private const TELEMETRY_BASE_URIS = [
        self::DEV => 'https://telemetry.dev.paypercut.net/',
        self::STAGE => 'https://telemetry.stage.paypercut.net/',
        self::PRODUCTION => 'https://telemetry.paypercut.io/',
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * The stored environment, or '' when it is unset or not one we know.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEnvironment($storeId = null): string
    {
        return self::normalise((string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Resolve a stored value to one of the environments this module knows.
     *
     * @param string $environment
     * @return string
     */
    public static function normalise(string $environment): string
    {
        if ($environment === self::LEGACY_SANDBOX) {
            return self::PRODUCTION;
        }

        return isset(self::API_BASE_URIS[$environment]) ? $environment : '';
    }

    /**
     * Payment API base, with a trailing slash.
     *
     * Falls back to production for an unknown environment: this is the payment
     * path, and a store with a stale value must keep taking money.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiBaseUri($storeId = null): string
    {
        return self::apiBaseUriFor($this->getEnvironment($storeId));
    }

    /**
     * Payment API base for an explicit environment string.
     *
     * @param string $environment
     * @return string
     */
    public static function apiBaseUriFor(string $environment): string
    {
        $base = self::API_BASE_URIS[self::normalise($environment)] ?? self::DEFAULT_API_BASE_URI;

        return self::allowedPaypercutBase($base) ?: self::DEFAULT_API_BASE_URI;
    }

    /**
     * Telemetry edge base, with a trailing slash, or '' when there is none.
     *
     * Unlike the payment API this does NOT fall back to production: an unknown
     * environment must yield no debug session rather than a confusing one.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTelemetryBaseUri($storeId = null): string
    {
        return self::telemetryBaseUriFor($this->getEnvironment($storeId));
    }

    /**
     * Telemetry edge base for an explicit environment string.
     *
     * @param string $environment
     * @return string
     */
    public static function telemetryBaseUriFor(string $environment): string
    {
        $environment = self::normalise($environment);

        if (!isset(self::TELEMETRY_BASE_URIS[$environment])) {
            return '';
        }

        return self::allowedPaypercutBase(self::TELEMETRY_BASE_URIS[$environment]);
    }

    /**
     * Accept a base URI only on an https Paypercut host.
     *
     * A credential travels on the mint request, so the destination is checked
     * rather than trusted. The `\z/D` anchor is load-bearing: it rejects
     * `https://paypercut.io.evil.com/`, `https://notpaypercut.io/` and any
     * plain-http host.
     *
     * @param string $url
     * @return string
     */
    public static function allowedPaypercutBase(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '' || !preg_match('/(^|\.)paypercut\.(net|io)\z/D', $host)) {
            return '';
        }

        return rtrim($url, '/') . '/';
    }
}
