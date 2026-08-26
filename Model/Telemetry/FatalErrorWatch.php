<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

/**
 * Reports the fatal errors a debug session would otherwise never see.
 *
 * A fatal on the checkout page breaks our payment form whichever module raised
 * it, and it never reaches a catch block — so the session sees nothing at all
 * unless the shutdown handler looks.
 */
class FatalErrorWatch
{
    /**
     * The levels that end a request. A warning is noise; these are the bug.
     */
    const FATAL_LEVELS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * @var bool
     */
    private $registered = false;

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var EventRecorder
     */
    private $recorder;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @param TelemetrySession $session
     * @param EventRecorder $recorder
     * @param EventQueue $queue
     */
    public function __construct(TelemetrySession $session, EventRecorder $recorder, EventQueue $queue)
    {
        $this->session = $session;
        $this->recorder = $recorder;
        $this->queue = $queue;
    }

    /**
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        register_shutdown_function([$this, 'report']);
    }

    /**
     * Record the fatal that ended this request, if there was one.
     *
     * @return void
     */
    public function report(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'] ?? 0, self::FATAL_LEVELS, true)) {
            return;
        }

        if (!$this->session->isActiveFast()) {
            return;
        }

        // Drain whatever this request buffered first, so the fatal lands after
        // the events that led to it rather than in front of them.
        $this->recorder->persist();

        $event = Event::fatal(
            (string) ($error['message'] ?? ''),
            (string) ($error['file'] ?? ''),
            (int) ($error['line'] ?? 0),
            (int) ($error['type'] ?? 0)
        );

        // The recorder's own shutdown handler may never run after a fatal, so
        // this writes directly rather than buffering for a flush that will not
        // come.
        $this->queue->append([$event->envelope()]);
    }
}
