<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/**
 * The buffered, best-effort store of diagnostic events awaiting delivery.
 *
 * Storefront requests only ever append here; delivery happens later, from an
 * authenticated admin request. Everything is capped, because a queue that can
 * grow without bound on a busy store is a denial of service against the store.
 */
class EventQueue
{
    /**
     * @var Store
     */
    private $store;

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param Store $store
     * @param TelemetrySession $session
     * @param State $appState
     */
    public function __construct(
        Store $store,
        TelemetrySession $session,
        State $appState
    ) {
        $this->store = $store;
        $this->session = $session;
        $this->appState = $appState;
    }

    /**
     * Append envelopes, dropping the oldest if that overflows the caps.
     *
     * @param array $envelopes
     * @return void
     */
    public function append(array $envelopes): void
    {
        $envelopes = $this->assertSafe($envelopes);

        if (empty($envelopes)) {
            return;
        }

        $capped = self::cap(array_merge($this->read(Store::QUEUE_KEY), $envelopes));

        $this->write(Store::QUEUE_KEY, $capped['envelopes']);

        // Counted only from admin requests. A storefront request must make at
        // most one write, and the queue write above is it — the panel already
        // presents this counter as approximate.
        if ($capped['dropped'] > 0 && $this->isAdminArea()) {
            $this->session->updateRuntime([
                'events_dropped' => (int) ($this->session->runtime()['events_dropped'] ?? 0) + $capped['dropped'],
            ]);
        }
    }

    /**
     * The last gate before anything is persisted for delivery.
     *
     * Every producer funnels through here — the storefront recorder and the
     * admin-side lifecycle events alike — so the deny assertion cannot be
     * bypassed by adding a new call site. A tripped assertion drops the whole
     * event, not the offending field: an event assembled wrongly cannot be
     * trusted in its other parts either.
     *
     * The envelope is screened as it will be sent — every field of it, not a
     * named subset. `order_ref` and the payment ids are on the wire like
     * anything else, and on the webhook paths they come from a request body
     * this store did not author.
     *
     * @param array $envelopes
     * @return array
     */
    private function assertSafe(array $envelopes): array
    {
        if (empty($envelopes)) {
            return [];
        }

        $secrets = $this->session->credentials();
        $safe = [];

        foreach ($envelopes as $envelope) {
            if (Event::envelopeDenied($envelope, $secrets)) {
                $this->session->audit(
                    'Telemetry: event dropped by the deny assertion',
                    ['event' => (string) ($envelope['event'] ?? 'unknown')]
                );

                continue;
            }

            $safe[] = $envelope;
        }

        return $safe;
    }

    /**
     * Enforce the queue caps, dropping the oldest entries first.
     *
     * @param array $envelopes
     * @return array
     */
    public static function cap(array $envelopes): array
    {
        $dropped = 0;

        if (count($envelopes) > TelemetrySession::MAX_QUEUE_EVENTS) {
            $dropped = count($envelopes) - TelemetrySession::MAX_QUEUE_EVENTS;
            $envelopes = array_slice($envelopes, -TelemetrySession::MAX_QUEUE_EVENTS);
        }

        // Stop at one, mirroring splitBatch(): a single oversized envelope must
        // not empty the queue behind it.
        while (count($envelopes) > 1 && self::bytes($envelopes) > TelemetrySession::MAX_QUEUE_BYTES) {
            array_shift($envelopes);
            $dropped++;
        }

        return [
            'envelopes' => $envelopes,
            'dropped' => $dropped,
        ];
    }

    /**
     * Split a batch off the front of the queue, within both edge bounds.
     *
     * Always takes at least one envelope: a single oversized envelope would
     * otherwise wedge the queue forever, and the edge rejecting it once is a
     * cheaper outcome than never draining. Never drops and never reorders.
     *
     * @param array $envelopes
     * @param int $maxBytes
     * @param int $maxEvents
     * @return array
     */
    public static function splitBatch(array $envelopes, int $maxBytes, int $maxEvents): array
    {
        $batch = [];

        foreach ($envelopes as $envelope) {
            if (count($batch) >= $maxEvents) {
                break;
            }

            $candidate = array_merge($batch, [$envelope]);

            if (!empty($batch) && self::bytes($candidate) > $maxBytes) {
                break;
            }

            $batch = $candidate;
        }

        return [
            'batch' => $batch,
            'remainder' => array_slice($envelopes, count($batch)),
        ];
    }

    /**
     * Take a batch, shortening the stored queue immediately.
     *
     * The remainder is written back BEFORE the network call, and the batch is
     * parked under its own key. Holding the remainder across the request would
     * discard anything storefront requests appended while the POST was in
     * flight, and could resurrect an already-delivered batch.
     *
     * @param int $maxBytes
     * @param int $maxEvents
     * @return array
     */
    public function takeBatch(int $maxBytes, int $maxEvents): array
    {
        $split = self::splitBatch($this->read(Store::QUEUE_KEY), $maxBytes, $maxEvents);

        if (empty($split['batch'])) {
            return [];
        }

        $this->write(Store::QUEUE_KEY, $split['remainder']);
        $this->write(Store::INFLIGHT_KEY, $split['batch']);

        return $split['batch'];
    }

    /**
     * A batch that was taken but whose delivery has not been settled.
     *
     * @return array
     */
    public function inflight(): array
    {
        return $this->read(Store::INFLIGHT_KEY);
    }

    /**
     * @return void
     */
    public function clearInflight(): void
    {
        $this->store->deleteExpiring(Store::INFLIGHT_KEY);
    }

    /**
     * Shorten the parked batch to what is left to deliver.
     *
     * The flusher may only ever SHORTEN inflight, never write the queue: the
     * flush lock excludes other flushers, but append() is an unlocked
     * read-modify-write from anonymous storefront requests, and takeBatch() has
     * already removed this batch from the queue.
     *
     * @param array $envelopes
     * @return void
     */
    public function retainInflight(array $envelopes): void
    {
        $this->write(Store::INFLIGHT_KEY, $envelopes);
    }

    /**
     * @return int
     */
    public function size(): int
    {
        return count($this->read(Store::QUEUE_KEY)) + count($this->read(Store::INFLIGHT_KEY));
    }

    /**
     * @param array $envelopes
     * @return int
     */
    public static function bytes(array $envelopes): int
    {
        $json = json_encode($envelopes);

        return is_string($json) ? strlen($json) : 0;
    }

    /**
     * @param string $key
     * @return array
     */
    private function read(string $key): array
    {
        $stored = $this->store->getExpiring($key);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param string $key
     * @param array $envelopes
     * @return void
     */
    private function write(string $key, array $envelopes): void
    {
        $this->store->putExpiring($key, $envelopes, $this->ttl());
    }

    /**
     * Outlive the session slightly, so a final flush still finds its batch.
     *
     * @return int
     */
    private function ttl(): int
    {
        $expiresAt = (int) ($this->session->record()['expires_at'] ?? 0);

        return max(300, ($expiresAt - time()) + 300);
    }

    /**
     * @return bool
     */
    private function isAdminArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Exception $exception) {
            return false;
        }
    }
}
