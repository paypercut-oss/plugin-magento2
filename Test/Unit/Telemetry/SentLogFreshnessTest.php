<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use PHPUnit\Framework\TestCase;

/**
 * Starting a session clears the sent log server-side, but the panel is rendered
 * once per page load. Both halves of the fix have to stay in place or the
 * merchant expands the block and reads the previous session's events under the
 * new session's heading.
 */
class SentLogFreshnessTest extends TestCase
{
    public function testBeginningASessionClearsTheLog(): void
    {
        $this->assertStringContainsString(
            '$this->sentLog->clear();',
            $this->read('Model/Telemetry/TelemetrySession.php')
        );
    }

    public function testTheRenderedLogBlockIsAddressable(): void
    {
        $this->assertStringContainsString(
            'data-paypercut-log',
            $this->read('view/adminhtml/templates/system/config/debug-session.phtml')
        );
    }

    public function testTheScriptDropsTheStaleBlockOnStart(): void
    {
        $script = $this->read('view/adminhtml/web/js/debug-session.js');

        $this->assertMatchesRegularExpression(
            '/if \(started\) \{.*dropSentLog\(\);.*\}/s',
            $script
        );
    }

    public function testTheLiveAndFinishedSessionIdLabelsDiffer(): void
    {
        $panel = $this->read('view/adminhtml/templates/system/config/debug-session.phtml');

        // A finished session's identifier stays on screen until a new one
        // starts; labelled the same as a live one, the merchant quotes a dead
        // id at support and watches it change under them when they press Start.
        $this->assertStringContainsString("__('Session ID')", $panel);
        $this->assertStringContainsString("__('Last session ID')", $panel);
    }

    public function testTheFailedStateOffersOnlyTheTraceId(): void
    {
        $panel = $this->read('view/adminhtml/templates/system/config/debug-session.phtml');

        $this->assertStringContainsString('data-paypercut-trace-id', $panel);
        $this->assertStringNotContainsString('data-paypercut-request-id', $panel);
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
