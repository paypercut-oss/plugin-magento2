<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Support\Environment;
use PHPUnit\Framework\TestCase;

/**
 * The merchant-facing promise lives in two places. A merchant agreeing to one
 * thing while the documentation says another is a real problem regardless of
 * who is reading, so this fails the build when they drift.
 */
class DisclosureTest extends TestCase
{
    const DISCLOSURE = 'view/adminhtml/templates/system/config/debug-session-disclosure.phtml';

    const README = 'README.md';

    /**
     * @dataProvider promises
     * @param string $sentence
     */
    public function testEveryPromiseAppearsInBothPlaces(string $sentence): void
    {
        $panel = $this->normalise($this->read(self::DISCLOSURE));
        $readme = $this->normalise($this->read(self::README));
        $needle = $this->normalise($sentence);

        $this->assertStringContainsString($needle, $panel, 'missing from the consent panel');
        $this->assertStringContainsString($needle, $readme, 'missing from README.md');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function promises(): array
    {
        return [
            'not shared' => ['customer names, email addresses, billing or shipping addresses, order totals, line items, payment card data, the reason text you type when issuing a refund, or any API key, webhook secret or password.'],
            'what is shared' => ['Module, Magento, PHP and theme versions; the third-party modules enabled on this store and their versions; how this store has the Paypercut payment methods configured (which options are switched on — never the values of your credentials); a record of each checkout, refund and payment notification the module handled and whether it succeeded, identified by Magento order number and Paypercut payment reference; when something fails, the type of error, the file and line it came from, and which module or theme raised it — never error text written by Magento itself, which can quote your order data back; and when the session started and stopped.'],
            'the key is not sent' => ['Your API key is never sent to the telemetry service. It is used once, over HTTPS, to obtain a short-lived diagnostic token from the Paypercut API this store is connected to — api.paypercut.io for a production store.'],
            'retention' => ['Paypercut keeps this diagnostic data for 30 days.'],
        ];
    }

    /**
     * The copy is checked against the CODE, not only against README.md.
     *
     * A merchant reads one named host and gets whatever the environment map
     * resolves to; the two are pinned together here so a new entry in that map
     * has to face the disclosure.
     */
    public function testTheNamedHostIsTheOneAProductionStoreReaches(): void
    {
        $this->assertStringContainsString('api.paypercut.io', $this->read(self::DISCLOSURE));
        $this->assertSame('https://api.paypercut.io/', Environment::apiBaseUriFor(Environment::PRODUCTION));
        $this->assertSame(
            'https://telemetry.paypercut.io/',
            Environment::telemetryBaseUriFor(Environment::PRODUCTION)
        );
    }

    /**
     * @dataProvider environments
     * @param string $environment
     */
    public function testNoEnvironmentResolvesOffAPaypercutHost(string $environment): void
    {
        foreach ([
            Environment::apiBaseUriFor($environment),
            Environment::telemetryBaseUriFor($environment),
        ] as $base) {
            if ($base !== '') {
                $this->assertSame($base, Environment::allowedPaypercutBase($base), $base . ' is not a Paypercut host');
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function environments(): array
    {
        return [
            'production' => [Environment::PRODUCTION],
            'stage' => [Environment::STAGE],
            'dev' => [Environment::DEV],
            'the legacy sandbox value' => [Environment::LEGACY_SANDBOX],
            'unset' => [''],
        ];
    }

    /**
     * Whitespace and case only — the wording itself must match.
     *
     * @param string $text
     * @return string
     */
    private function normalise(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param string $relative
     * @return string
     */
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
