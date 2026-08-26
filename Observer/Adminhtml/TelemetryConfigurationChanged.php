<?php
declare(strict_types=1);

namespace Paypercut\Payment\Observer\Adminhtml;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Paypercut\Payment\Model\Telemetry\EnvironmentSnapshot;
use Paypercut\Payment\Model\Telemetry\Event;
use Paypercut\Payment\Model\Telemetry\EventQueue;
use Paypercut\Payment\Model\Telemetry\TelemetrySession;

/**
 * Re-send the configuration snapshot when the payment settings change mid-session.
 *
 * A session opens with one configuration snapshot; without this, a setting
 * changed mid-session is read against the snapshot taken before it and the
 * timeline lies. reap() runs first because a changed secret key or environment
 * must end the session rather than report against it.
 */
class TelemetryConfigurationChanged implements ObserverInterface
{
    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var EnvironmentSnapshot
     */
    private $snapshot;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @param TelemetrySession $session
     * @param EnvironmentSnapshot $snapshot
     * @param EventQueue $queue
     */
    public function __construct(
        TelemetrySession $session,
        EnvironmentSnapshot $snapshot,
        EventQueue $queue
    ) {
        $this->session = $session;
        $this->snapshot = $snapshot;
        $this->queue = $queue;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->session->reap();

        if (!$this->session->isActiveFast()) {
            return;
        }

        $this->queue->append([
            Event::environmentConfiguration($this->snapshot->values())->envelope(),
        ]);
    }
}
