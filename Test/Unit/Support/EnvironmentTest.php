<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Support;

use Paypercut\Payment\Model\Support\Environment;
use PHPUnit\Framework\TestCase;

/**
 * The environment pairing. A token minted for one environment is rejected by
 * every other environment's edge, so the two hosts have to move together.
 */
class EnvironmentTest extends TestCase
{
    /**
     * @dataProvider pairs
     * @param string $environment
     * @param string $apiBase
     * @param string $edgeBase
     */
    public function testBothHostsComeFromTheSameEnvironment(
        string $environment,
        string $apiBase,
        string $edgeBase
    ): void {
        $this->assertSame($apiBase, Environment::apiBaseUriFor($environment));
        $this->assertSame($edgeBase, Environment::telemetryBaseUriFor($environment));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pairs(): array
    {
        return [
            'dev' => ['dev', 'https://api.dev.paypercut.net/', 'https://telemetry.dev.paypercut.net/'],
            'stage' => ['stage', 'https://api.stage.paypercut.net/', 'https://telemetry.stage.paypercut.net/'],
            'production' => ['production', 'https://api.paypercut.io/', 'https://telemetry.paypercut.io/'],
        ];
    }

    /**
     * @dataProvider unknownEnvironments
     * @param string $environment
     */
    public function testAnUnknownEnvironmentFallsBackForPaymentsButNotForTelemetry(string $environment): void
    {
        $this->assertSame('https://api.paypercut.io/', Environment::apiBaseUriFor($environment));
        $this->assertSame('', Environment::telemetryBaseUriFor($environment));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownEnvironments(): array
    {
        return [
            'never set' => [''],
            'nonsense' => ['staging-2'],
        ];
    }

    /**
     * Sandbox and production always resolved to the same payment API host, so
     * a store still holding the legacy value keeps a coherent pair rather than
     * a production payment API and no telemetry.
     */
    public function testTheLegacySandboxValuePairsAsProduction(): void
    {
        $this->assertSame('https://api.paypercut.io/', Environment::apiBaseUriFor('sandbox'));
        $this->assertSame('https://telemetry.paypercut.io/', Environment::telemetryBaseUriFor('sandbox'));
        $this->assertSame(Environment::PRODUCTION, Environment::normalise('sandbox'));
    }

    /**
     * @dataProvider refusedBases
     * @param string $url
     */
    public function testOnlyHttpsPaypercutHostsAreAccepted(string $url): void
    {
        $this->assertSame('', Environment::allowedPaypercutBase($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedBases(): array
    {
        return [
            'a lookalike suffix' => ['https://paypercut.io.evil.com/'],
            'a lookalike prefix' => ['https://notpaypercut.io/'],
            'a lookalike tld' => ['https://paypercut.io.co/'],
            'plain http' => ['http://api.paypercut.io/'],
            'no host' => ['https:///v1'],
            'empty' => [''],
        ];
    }

    public function testAcceptedBasesAlwaysCarryATrailingSlash(): void
    {
        $this->assertSame('https://api.paypercut.io/', Environment::allowedPaypercutBase('https://api.paypercut.io'));
        $this->assertSame(
            'https://telemetry.dev.paypercut.net/',
            Environment::allowedPaypercutBase('https://telemetry.dev.paypercut.net/')
        );
    }
}
