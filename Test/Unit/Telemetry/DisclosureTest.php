<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

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
            'what is shared' => ['Module, Magento, PHP and theme versions; the third-party modules enabled on this store and their versions; how this store has the Paypercut payment methods configured (which options are switched on — never the values of your credentials); a record of each checkout, refund and payment notification the module handled and whether it succeeded, identified by Magento order number and Paypercut payment reference; when something fails, the error message, the file and line it came from, and which module or theme raised it; and when the session started and stopped.'],
            'the key is not sent' => ['Your API key is never sent to the telemetry service. It is used once, over HTTPS, to obtain a short-lived diagnostic token from api.paypercut.io.'],
            'retention' => ['Paypercut keeps this diagnostic data for 30 days.'],
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
