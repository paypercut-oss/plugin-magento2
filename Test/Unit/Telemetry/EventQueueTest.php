<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Telemetry\EventQueue;
use Paypercut\Payment\Model\Telemetry\TelemetrySession;
use PHPUnit\Framework\TestCase;

/**
 * Capping and batch splitting: the two places a bug silently loses a merchant's
 * diagnostics or wedges the queue for good.
 */
class EventQueueTest extends TestCase
{
    public function testCappingDropsTheOldestFirst(): void
    {
        $envelopes = [];

        for ($i = 0; $i < TelemetrySession::MAX_QUEUE_EVENTS + 5; $i++) {
            $envelopes[] = ['event' => 'e' . $i];
        }

        $capped = EventQueue::cap($envelopes);

        $this->assertSame(5, $capped['dropped']);
        $this->assertCount(TelemetrySession::MAX_QUEUE_EVENTS, $capped['envelopes']);
        $this->assertSame('e5', $capped['envelopes'][0]['event']);
    }

    public function testCappingNeverEmptiesTheQueueForOneOversizedEnvelope(): void
    {
        $capped = EventQueue::cap([
            ['event' => 'huge', 'attrs' => ['note' => str_repeat('x', TelemetrySession::MAX_QUEUE_BYTES * 2)]],
        ]);

        $this->assertCount(1, $capped['envelopes']);
        $this->assertSame(0, $capped['dropped']);
    }

    public function testSplittingNeitherDropsNorReorders(): void
    {
        $envelopes = [];

        for ($i = 0; $i < 30; $i++) {
            $envelopes[] = ['event' => 'e' . $i];
        }

        $split = EventQueue::splitBatch($envelopes, 200, 50);

        $this->assertSame($envelopes, array_merge($split['batch'], $split['remainder']));
    }

    public function testSplittingAlwaysTakesAtLeastOneEnvelope(): void
    {
        $split = EventQueue::splitBatch([
            ['event' => 'huge', 'attrs' => ['note' => str_repeat('x', 5000)]],
            ['event' => 'small'],
        ], 100, 50);

        $this->assertCount(1, $split['batch']);
        $this->assertSame('huge', $split['batch'][0]['event']);
        $this->assertCount(1, $split['remainder']);
    }

    public function testSplittingHonoursTheEventCap(): void
    {
        $envelopes = array_fill(0, 60, ['event' => 'e']);

        $split = EventQueue::splitBatch($envelopes, PHP_INT_MAX, TelemetrySession::MAX_BATCH_EVENTS);

        $this->assertCount(TelemetrySession::MAX_BATCH_EVENTS, $split['batch']);
        $this->assertCount(10, $split['remainder']);
    }

    public function testBytesIsTheJsonLengthOfTheBatch(): void
    {
        $this->assertSame(strlen('[{"event":"e"}]'), EventQueue::bytes([['event' => 'e']]));
    }
}
