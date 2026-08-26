<?php
declare(strict_types=1);

namespace Paypercut\Payment\Observer\Adminhtml;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Paypercut\Payment\Model\Telemetry\EventQueue;
use Paypercut\Payment\Model\Telemetry\Flusher;
use Paypercut\Payment\Model\Telemetry\TelemetrySession;

/**
 * Expire the session and drain the queue on ordinary admin page loads.
 *
 * The guard is deliberately stricter than "this is the admin area": the
 * observer only runs for a request that already carries an authenticated
 * backend session, and it skips the panel's own endpoints, which do their own
 * reap and flush. This is the backstop for a merchant who started a session and
 * navigated away; the panel poll is the primary delivery trigger.
 */
class ReapAndFlushTelemetry implements ObserverInterface
{
    const OWN_ROUTE = 'paypercut';

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var Flusher
     */
    private $flusher;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @var AuthSession
     */
    private $authSession;

    /**
     * @param TelemetrySession $session
     * @param Flusher $flusher
     * @param EventQueue $queue
     * @param AuthSession $authSession
     */
    public function __construct(
        TelemetrySession $session,
        Flusher $flusher,
        EventQueue $queue,
        AuthSession $authSession
    ) {
        $this->session = $session;
        $this->flusher = $flusher;
        $this->queue = $queue;
        $this->authSession = $authSession;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getData('request');

        if ($request instanceof RequestInterface && $request->getModuleName() === self::OWN_ROUTE) {
            return;
        }

        if (!$this->authSession->isLoggedIn()) {
            return;
        }

        if (($this->session->record()['status'] ?? '') !== 'active') {
            return;
        }

        $this->session->reap();

        if (!$this->session->isActiveFast() || $this->queue->size() === 0) {
            return;
        }

        $this->flusher->flushOnce();
    }
}
