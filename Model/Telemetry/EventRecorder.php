<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

/**
 * The only surface the rest of the module uses to report a diagnostic event.
 *
 * Call sites hand events here from anywhere, including anonymous checkout,
 * webhook and cron requests. The contract those call sites rely on is that
 * record() is nearly free and never reaches the network: when no session is
 * running it reads one already-cached config value and returns.
 */
class EventRecorder
{
    /**
     * @var array
     */
    private $buffer = [];

    /**
     * @var bool
     */
    private $registered = false;

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @param TelemetrySession $session
     * @param EventQueue $queue
     */
    public function __construct(TelemetrySession $session, EventQueue $queue)
    {
        $this->session = $session;
        $this->queue = $queue;
    }

    /**
     * Buffer one event for later delivery.
     *
     * Never sends, and never tears a session down: that belongs to admin
     * requests, because this runs on the checkout path. The request's whole
     * contribution is one queue write at shutdown, however many events it
     * buffered.
     *
     * @param Event $event
     * @return void
     */
    public function record(Event $event): void
    {
        if (!$this->session->isActiveFast()) {
            return;
        }

        // The deny assertion lives in EventQueue::append() so that it covers
        // every producer, including the admin-side lifecycle events. Nothing
        // here may write: this runs on checkout requests.
        $this->buffer[] = $event->envelope();

        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function([$this, 'persist']);
        }
    }

    /**
     * Write the request's buffered events to the queue, once, at shutdown.
     *
     * One capped write per request rather than one per event: concurrent
     * storefront requests read-modify-write the same key, so fewer writes means
     * fewer lost updates. Delivery is best-effort by design and the panel
     * reports a dropped count; this is diagnostic data, never an audit trail.
     *
     * @return void
     */
    public function persist(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $buffer = $this->buffer;
        $this->buffer = [];

        $this->queue->append($buffer);
    }
}
