<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Telemetry\Flusher;
use PHPUnit\Framework\TestCase;

/**
 * The delivery decision table, exercised without an edge, a database or a session.
 */
class FlusherDecideTest extends TestCase
{
    public function testAcceptedClearsTheBatch(): void
    {
        $decision = Flusher::decide(202, 0, 0);

        $this->assertSame('accepted', $decision['outcome']);
        $this->assertTrue($decision['clears_batch']);
        $this->assertFalse($decision['end_session']);
    }

    public function testUnauthorisedEndsTheSessionAndNeverRetries(): void
    {
        $decision = Flusher::decide(401, 0, 0);

        $this->assertSame('token_rejected', $decision['outcome']);
        $this->assertTrue($decision['end_session']);
        $this->assertSame(0, $decision['retry_in']);
    }

    public function testTooLargeIsNotAFailure(): void
    {
        foreach ([0, 1, 2, 3, 9] as $failures) {
            $decision = Flusher::decide(413, 0, $failures);

            $this->assertSame('split', $decision['outcome']);
            $this->assertFalse($decision['end_session']);
            $this->assertFalse($decision['clears_batch']);
        }
    }

    public function testThrottlingHonoursRetryAfterWithinAnHourAndAQuarter(): void
    {
        $this->assertSame(45, Flusher::decide(429, 45, 0)['retry_in']);
        $this->assertSame(60, Flusher::decide(429, 0, 0)['retry_in']);
        $this->assertSame(900, Flusher::decide(429, 100000, 0)['retry_in']);
        $this->assertFalse(Flusher::decide(429, 0, 3)['end_session']);
    }

    public function testAnUnreadyEdgeNeverEndsTheSession(): void
    {
        foreach ([503, 504] as $status) {
            foreach ([0, 5, 50] as $failures) {
                $decision = Flusher::decide($status, 0, $failures);

                $this->assertSame('unready', $decision['outcome']);
                $this->assertFalse($decision['end_session']);
                $this->assertSame(120, $decision['retry_in']);
            }
        }
    }

    public function testAMalformedBatchIsDroppedButStillCounted(): void
    {
        $decision = Flusher::decide(400, 0, 0);

        $this->assertSame('poison', $decision['outcome']);
        $this->assertTrue($decision['clears_batch']);
        $this->assertFalse($decision['end_session']);
    }

    public function testTheGiveUpLadder(): void
    {
        $this->assertSame(30, Flusher::decide(0, 0, 0)['retry_in']);
        $this->assertSame(120, Flusher::decide(0, 0, 1)['retry_in']);
        $this->assertSame(300, Flusher::decide(0, 0, 2)['retry_in']);
        $this->assertSame(300, Flusher::decide(0, 0, 3)['retry_in']);

        $this->assertFalse(Flusher::decide(0, 0, 2)['end_session']);
        $this->assertTrue(Flusher::decide(0, 0, 3)['end_session']);
        $this->assertTrue(Flusher::decide(400, 0, 3)['end_session']);
    }

    public function testATransportFailureDoesNotClearTheBatch(): void
    {
        $decision = Flusher::decide(0, 0, 0);

        $this->assertSame('failed', $decision['outcome']);
        $this->assertFalse($decision['clears_batch']);
    }
}
