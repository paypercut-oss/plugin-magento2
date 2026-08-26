<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the catalogue honest.
 *
 * An event added at a call site nobody documented fails the build, and so does
 * a payment-outcome path that stops reporting. Each file on the list below was
 * silent before, which is why a merchant's "it just did nothing" was
 * unanswerable; a file dropping off it is that regression returning.
 */
class EventCatalogTest extends TestCase
{
    const DOCS = 'docs/telemetry.md';

    /**
     * Paths that decide whether a shopper's money became an order.
     */
    const PAYMENT_OUTCOME_PATHS = [
        'Controller/Payment/Redirect.php',
        'Controller/Payment/Success.php',
        'Controller/Payment/Cancel.php',
        'Controller/Payment/Ipn.php',
        'Controller/Payment/BnplCallback.php',
        'Cron/BnplStatusCheck.php',
        'Model/Form.php',
        'Model/Api/Client.php',
        'Model/PaypercutOrderHelper.php',
        'Observer/CreateRefundAfterCreditMemo.php',
        'Observer/CreateSubscriptionAfterOrderPlace.php',
    ];

    public function testEveryEmittedEventIsDocumented(): void
    {
        $catalogue = (string) file_get_contents($this->path(self::DOCS));
        $undocumented = [];

        foreach ($this->emittedEventNames() as $name) {
            if (strpos($catalogue, '`' . $name . '`') === false) {
                $undocumented[] = $name;
            }
        }

        $this->assertSame([], $undocumented, 'events emitted but absent from ' . self::DOCS);
    }

    public function testEveryPaymentOutcomePathStillReports(): void
    {
        foreach (self::PAYMENT_OUTCOME_PATHS as $relative) {
            $source = (string) file_get_contents($this->path($relative));

            $this->assertStringContainsString(
                'recorder->record(',
                $source,
                $relative . ' no longer reports anything to a debug session'
            );
        }
    }

    public function testTheSuiteScannedSomething(): void
    {
        $this->assertGreaterThan(20, count($this->emittedEventNames()));
    }

    /**
     * @return string[]
     */
    private function emittedEventNames(): array
    {
        $names = [];
        $root = dirname(__DIR__, 3);
        $directories = ['Block', 'Controller', 'Cron', 'Model', 'Observer'];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/Event::(?:of|failure|apiFailure)\(\s*'([a-z0-9_.]+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches
                );

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        ksort($names);

        return array_keys($names);
    }

    /**
     * @param string $relative
     * @return string
     */
    private function path(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path);

        return $path;
    }
}
