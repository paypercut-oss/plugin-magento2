<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Delivers queued diagnostic events to the telemetry edge.
 *
 * Runs only from authenticated admin requests: the panel's status poll, the
 * Stop handler and one guarded admin backstop. Never from a storefront
 * request, never from the webhook, never from cron.
 */
class Flusher
{
    /**
     * Backoff ladder applied after consecutive delivery failures, in seconds.
     */
    const BACKOFF_SECONDS = [30, 120, 300];

    /**
     * @var EdgeClient
     */
    private $client;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var SentLog
     */
    private $sentLog;

    /**
     * @var ModuleVersion
     */
    private $moduleVersion;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param EdgeClient $client
     * @param EventQueue $queue
     * @param TelemetrySession $session
     * @param SentLog $sentLog
     * @param ModuleVersion $moduleVersion
     * @param Random $random
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        EdgeClient $client,
        EventQueue $queue,
        TelemetrySession $session,
        SentLog $sentLog,
        ModuleVersion $moduleVersion,
        Random $random,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->queue = $queue;
        $this->session = $session;
        $this->sentLog = $sentLog;
        $this->moduleVersion = $moduleVersion;
        $this->random = $random;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Attempt to deliver at most one batch.
     *
     * @return bool Whether a delivery was attempted.
     */
    public function flushOnce(): bool
    {
        $record = $this->session->record();

        if (($record['status'] ?? '') !== 'active' || (int) ($record['expires_at'] ?? 0) <= time()) {
            return false;
        }

        if ((int) ($this->session->runtime()['next_attempt_at'] ?? 0) > time()) {
            return false;
        }

        if (!$this->session->claimFlushLock()) {
            return false;
        }

        try {
            return $this->deliver($record);
        } finally {
            $this->session->releaseFlushLock();
        }
    }

    /**
     * Decide what an edge response means, with no side effects.
     *
     * Kept pure and separate from settle() so the whole branch table —
     * including the give-up ladder — can be exercised without an edge, a
     * database or a running session.
     *
     * @param int $status HTTP status, or 0 for a transport failure.
     * @param int $retryAfter Value of the Retry-After header, 0 when absent.
     * @param int $failures Consecutive failures BEFORE this attempt.
     * @return array
     */
    public static function decide(int $status, int $retryAfter, int $failures): array
    {
        if ($status === 202) {
            return self::outcome('accepted', false, 0, true);
        }

        if ($status === 401) {
            // Never re-mint. Every mint issues a token with a fresh expiry and
            // nothing can revoke one, so a re-mint would leave a credential
            // valid past the window the merchant agreed to.
            return self::outcome('token_rejected', true, 0, true);
        }

        if ($status === 413) {
            // Not a failure — the batch is being reshaped. A backoff rung would
            // punish a successful negotiation, and a step towards giving up
            // would end a session over a batch we can simply cut in half.
            return self::outcome('split', false, 0, false);
        }

        // Nothing in the edge answers 429; this covers infrastructure in front
        // of it. A hostile Retry-After must not park the session forever.
        if ($status === 429) {
            return self::outcome('throttled', false, $retryAfter > 0 ? min($retryAfter, 900) : 60, false);
        }

        if ($status === 503 || $status === 504) {
            // "My verification keys aren't ready" is a statement about the
            // edge, not about this token. Ending the session on a rolling
            // deploy would be a one-way door: there is no re-mint, so the
            // merchant would have to consent all over again.
            return self::outcome('unready', false, 120, false);
        }

        $attempt = $failures + 1;
        $giveUp = $attempt >= TelemetrySession::MAX_CONSECUTIVE_SEND_FAILURES;
        $retryIn = self::BACKOFF_SECONDS[min($attempt, count(self::BACKOFF_SECONDS)) - 1];

        // Our bug, not the merchant's: drop the batch so the queue drains, but
        // still count it. An edge that rejects every batch we build makes the
        // session useless, and it should end rather than burn an hour silently
        // incrementing a dropped counter.
        if ($status === 400) {
            return self::outcome('poison', $giveUp, $retryIn, true);
        }

        return self::outcome('failed', $giveUp, $retryIn, false);
    }

    /**
     * @param array $record
     * @return bool
     */
    private function deliver(array $record): bool
    {
        $maxEvents = $this->maxEvents();

        // A parked batch always drains first, so a retry never reorders delivery.
        $batch = $this->queue->inflight();

        if (empty($batch)) {
            $batch = $this->queue->takeBatch(TelemetrySession::MAX_BATCH_BYTES, $maxEvents);
        }

        if (empty($batch)) {
            return false;
        }

        $token = $this->session->token();

        if ($token === '') {
            $this->session->end('token_lost');
            return false;
        }

        $edgeBase = (string) ($record['edge_base'] ?? '');

        if ($edgeBase === '') {
            $this->session->end('environment_changed');
            return false;
        }

        $client = $this->clientIdentity();
        $split = EventQueue::splitBatch($batch, $this->eventsBudget($client), $maxEvents);
        $head = $split['batch'];
        $tail = $split['remainder'];

        $body = $this->json->serialize([
            'client' => $client,
            'events' => $head,
        ]);

        $result = $this->client->send($edgeBase, $token, $body);

        return $this->settle(
            (int) $result['status'],
            (int) $result['retry_after'],
            $head,
            $tail,
            is_array($result['body'] ?? null) ? $result['body'] : []
        );
    }

    /**
     * Apply the edge's answer to the parked batch.
     *
     * @param int $status
     * @param int $retryAfter
     * @param array $head The events actually POSTed.
     * @param array $tail What stayed parked behind them.
     * @param array $body The edge's decoded response.
     * @return bool
     */
    private function settle(int $status, int $retryAfter, array $head, array $tail, array $body): bool
    {
        $runtime = $this->session->runtime();
        $failures = (int) ($runtime['consecutive_edge_failures'] ?? 0);
        $decision = self::decide($status, $retryAfter, $failures);
        $events = count($head);

        if ($decision['outcome'] === 'split') {
            return $this->resize($head, $tail, $body);
        }

        if ($decision['clears_batch']) {
            // Only the delivered head is settled; anything behind it stays parked.
            $this->queue->retainInflight($tail);
        }

        if ($decision['outcome'] === 'accepted') {
            // The edge drops malformed events individually and still answers
            // 202, so the counts it returns are the only honest accounting
            // available.
            $accepted = isset($body['accepted']) ? (int) $body['accepted'] : $events;
            $dropped = isset($body['dropped']) ? (int) $body['dropped'] : 0;

            $this->sentLog->append($head);

            $this->session->updateRuntime([
                'events_sent' => (int) ($runtime['events_sent'] ?? 0) + $accepted,
                'consecutive_edge_failures' => 0,
                'next_attempt_at' => 0,
                'last_error' => '',
            ]);

            if ($dropped > 0) {
                $this->countDropped($dropped, 'edge_dropped');
            }

            return true;
        }

        if ($decision['outcome'] === 'poison') {
            $this->countDropped($events, 'malformed_batch');
        }

        if ($decision['end_session']) {
            if ($decision['outcome'] !== 'token_rejected') {
                $this->logger->error('Paypercut telemetry: giving up on delivery', [
                    'status' => $status,
                    'failures' => $failures + 1,
                ]);
            }

            $this->session->end($decision['outcome'] === 'token_rejected' ? 'edge_rejected' : 'send_failed');

            return true;
        }

        $countsAsFailure = in_array($decision['outcome'], ['failed', 'poison'], true);

        $this->session->updateRuntime([
            'consecutive_edge_failures' => $countsAsFailure ? $failures + 1 : $failures,
            'next_attempt_at' => time() + $decision['retry_in'] + $this->jitter(),
            'last_error' => 'edge_' . $status,
        ]);

        return true;
    }

    /**
     * Answer a 413 by making the next batch smaller.
     *
     * The queue is never touched. The head stays parked and is re-split on the
     * next flush, which is one round trip later on purpose: each attempt blocks
     * the merchant's browser for up to the edge timeout.
     *
     * @param array $head
     * @param array $tail
     * @param array $body
     * @return bool
     */
    private function resize(array $head, array $tail, array $body): bool
    {
        if (count($head) === 1) {
            // A one-event batch cannot be split further, and `split` does not
            // advance the give-up ladder, so nothing else would break the loop.
            // Name and size only: the envelope is the one thing not to log.
            $this->queue->retainInflight($tail);
            $this->countDropped(1, 'oversize_event');
            $this->logger->error('Paypercut telemetry: event too large to deliver', [
                'event' => (string) ($head[0]['event'] ?? 'unknown'),
                'bytes' => EventQueue::bytes($head),
            ]);
        } else {
            // Halving guarantees progress on its own: a 413 raised by a proxy
            // in front of the edge carries no limits at all, and the edge's own
            // byte cap is larger than ours, so neither would shrink the batch.
            $advertised = $this->advertisedEvents($body);
            $halved = (int) max(1, intdiv(count($head), 2));

            $this->session->updateRuntime([
                'edge_max_events' => $advertised > 0 ? min($advertised, $halved) : $halved,
            ]);
        }

        $this->session->updateRuntime([
            'next_attempt_at' => 0,
            'last_error' => 'edge_413',
        ]);

        return true;
    }

    /**
     * Identifies the software that produced the batch.
     *
     * @return array
     */
    private function clientIdentity(): array
    {
        return [
            'platform' => 'magento2',
            'version' => Event::text($this->moduleVersion->get()) ?: 'dev',
        ];
    }

    /**
     * Bytes left for the events array once the wrapper is paid for.
     *
     * The edge caps the request body, not the events array.
     *
     * @param array $client
     * @return int
     */
    private function eventsBudget(array $client): int
    {
        $wrapper = $this->json->serialize([
            'client' => $client,
            'events' => [],
        ]);

        return TelemetrySession::MAX_BATCH_BYTES - strlen($wrapper);
    }

    /**
     * The event cap a batch must satisfy, as last advertised by the edge.
     *
     * Clamped on the way in: the edge may only ever make us more conservative.
     *
     * @return int
     */
    private function maxEvents(): int
    {
        $events = (int) ($this->session->runtime()['edge_max_events'] ?? 0);

        return $events > 0
            ? max(1, min($events, TelemetrySession::MAX_BATCH_EVENTS))
            : TelemetrySession::MAX_BATCH_EVENTS;
    }

    /**
     * The event cap the edge named in its 413, or 0 when it named none.
     *
     * @param array $body
     * @return int
     */
    private function advertisedEvents(array $body): int
    {
        $limits = isset($body['limits']) && is_array($body['limits']) ? $body['limits'] : [];

        return (int) ($limits['max_events'] ?? 0);
    }

    /**
     * @param int $events
     * @param string $reason
     * @return void
     */
    private function countDropped(int $events, string $reason): void
    {
        $this->logger->error('Paypercut telemetry: batch dropped', [
            'events' => $events,
            'reason' => $reason,
        ]);

        $this->session->updateRuntime([
            'events_dropped' => (int) ($this->session->runtime()['events_dropped'] ?? 0) + $events,
        ]);
    }

    /**
     * @return int
     */
    private function jitter(): int
    {
        try {
            return $this->random->getRandomNumber(0, 30);
        } catch (\Exception $exception) {
            return 0;
        }
    }

    /**
     * @param string $outcome
     * @param bool $endSession
     * @param int $retryIn
     * @param bool $clearsBatch
     * @return array
     */
    private static function outcome(string $outcome, bool $endSession, int $retryIn, bool $clearsBatch): array
    {
        return [
            'outcome' => $outcome,
            'end_session' => $endSession,
            'retry_in' => $retryIn,
            'clears_batch' => $clearsBatch,
        ];
    }
}
