<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Paypercut\Payment\Model\Support\Environment;
use Psr\Log\LoggerInterface;

/**
 * The three merchant-facing operations behind the debug session panel.
 *
 * Everything here runs from an authenticated admin request. The controllers are
 * thin wrappers so the sequence — reap, lock, mint, re-check, publish — lives in
 * one place.
 */
class DebugSessionManager
{
    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var TokenMinter
     */
    private $minter;

    /**
     * @var MintErrorMapper
     */
    private $errorMapper;

    /**
     * @var EnvironmentSnapshot
     */
    private $snapshot;

    /**
     * @var ActiveModules
     */
    private $activeModules;

    /**
     * @var EventQueue
     */
    private $queue;

    /**
     * @var Flusher
     */
    private $flusher;

    /**
     * @var AuthSession
     */
    private $authSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TelemetrySession $session
     * @param TokenMinter $minter
     * @param MintErrorMapper $errorMapper
     * @param EnvironmentSnapshot $snapshot
     * @param ActiveModules $activeModules
     * @param EventQueue $queue
     * @param Flusher $flusher
     * @param AuthSession $authSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        TelemetrySession $session,
        TokenMinter $minter,
        MintErrorMapper $errorMapper,
        EnvironmentSnapshot $snapshot,
        ActiveModules $activeModules,
        EventQueue $queue,
        Flusher $flusher,
        AuthSession $authSession,
        LoggerInterface $logger
    ) {
        $this->session = $session;
        $this->minter = $minter;
        $this->errorMapper = $errorMapper;
        $this->snapshot = $snapshot;
        $this->activeModules = $activeModules;
        $this->queue = $queue;
        $this->flusher = $flusher;
        $this->authSession = $authSession;
        $this->logger = $logger;
    }

    /**
     * Mint a telemetry token and publish a session.
     *
     * @return array
     */
    public function start(): array
    {
        $this->session->reap();

        $state = $this->session->describe();

        if ($state['state'] === 'running') {
            return $this->ok(array_merge($state, ['already_running' => true]));
        }

        if (!$this->session->claimStartLock()) {
            return $this->error(
                ['message' => (string) __('A debug session is already being started.')],
                409
            );
        }

        try {
            return $this->mintAndPublish();
        } finally {
            $this->session->releaseStartLock();
        }
    }

    /**
     * End the session early at the merchant's request.
     *
     * @return array
     */
    public function stop(): array
    {
        $record = $this->session->record();
        $runtime = $this->session->runtime();

        if (($record['status'] ?? '') === 'active') {
            $this->queue->append([
                Event::sessionStopped(
                    (string) ($record['session_id'] ?? ''),
                    'merchant_stopped',
                    (int) ($runtime['events_sent'] ?? 0),
                    (int) ($runtime['events_dropped'] ?? 0)
                )->envelope(),
            ]);

            // Twice: the first pass clears anything already parked in flight,
            // the second carries the stop event itself. Without it, end() would
            // delete the queue holding the event that announces the stop.
            // Bounded on purpose — each pass can block for up to the edge
            // timeout, and this is a button click.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                if (!$this->flusher->flushOnce()) {
                    break;
                }
            }
        }

        $this->session->end('merchant_stopped');

        return $this->ok($this->session->describe());
    }

    /**
     * Reap, deliver, and report. Doubles as the delivery trigger while the
     * merchant has the settings screen open: an authenticated admin request is
     * the only place events are sent from.
     *
     * @return array
     */
    public function status(): array
    {
        $this->session->reap();
        $this->flusher->flushOnce();

        return $this->ok($this->session->describe());
    }

    /**
     * @return array
     */
    private function mintAndPublish(): array
    {
        $connection = $this->session->connection();

        if ($connection['secret'] === '') {
            return $this->error(
                ['message' => (string) __('Add the Paypercut Secret Key in API Credentials before starting a debug session.')],
                400
            );
        }

        // Both hosts come from this one environment value. A token minted for
        // one environment is rejected by every other environment's edge, so
        // they must never be resolved independently.
        $mintBase = Environment::apiBaseUriFor($connection['environment']);
        $edgeBase = Environment::telemetryBaseUriFor($connection['environment']);

        if ($edgeBase === '') {
            return $this->error(
                [
                    'message' => $connection['environment'] === ''
                        ? (string) __("This store's Paypercut connection doesn't record which environment it uses, so a debug session can't be started. Choose an Environment in API Credentials above, then try again.")
                        : (string) __("Debug sessions aren't available on this store's Paypercut environment."),
                ],
                400
            );
        }

        $response = $this->minter->mint($connection['secret'], $mintBase);
        $status = (int) $response['status'];

        if ($status !== 200) {
            return $this->reject($this->errorMapper->map($status, $response['body']), $response, $status);
        }

        if ($response['token'] === '' || $response['expires_at'] === '') {
            return $this->reject($this->errorMapper->badResponse(), $response, 502);
        }

        $now = time();
        $lifetime = TokenMinter::deriveLifetime($response['expires_at'], $response['date'], $now);
        $skew = TokenMinter::skew($response['date'], $now);

        if ($lifetime < TelemetrySession::MIN_LIFETIME_SECONDS) {
            return $this->reject($this->errorMapper->clockSkew($skew), $response, 400);
        }

        $expiresAt = $now + min($lifetime, TelemetrySession::SESSION_MAX_SECONDS) - TelemetrySession::SKEW_SECONDS;

        // Re-check under the lock: if anything else published a session while
        // the mint was in flight, discard this token rather than storing a
        // second one. An unreferenced token cannot be deleted by any teardown
        // path, and nothing can revoke it.
        $existing = $this->session->describe();

        if ($existing['state'] === 'running') {
            return $this->ok(array_merge($existing, ['already_running' => true]));
        }

        $sessionId = $this->session->newSessionId();
        $user = $this->authSession->getUser();

        $this->session->begin(
            [
                'status' => 'active',
                'session_id' => $sessionId,
                'environment' => $connection['environment'],
                'edge_base' => $edgeBase,
                'started_at' => $now,
                'expires_at' => $expiresAt,
                'started_by' => $user ? (int) $user->getId() : 0,
                'started_by_name' => $user ? Event::text((string) $user->getUserName()) : '',
                'key_fingerprint' => $this->session->fingerprint($connection['secret']),
                'ended_at' => 0,
                'reason_code' => '',
                'trace_id' => Event::text($response['trace_id']),
                'request_id' => Event::text($response['request_id']),
            ],
            $response['token']
        );

        $this->queue->append($this->openingEvents($sessionId, $connection['environment'], $expiresAt));

        $this->session->audit('Telemetry: debug session started', [
            'session_id' => $sessionId,
            'environment' => $connection['environment'],
            'expires_at' => $expiresAt,
            'clock_skew_s' => $skew,
        ]);

        return $this->ok($this->session->describe());
    }

    /**
     * @param string $sessionId
     * @param string $environment
     * @param int $expiresAt
     * @return array
     */
    private function openingEvents(string $sessionId, string $environment, int $expiresAt): array
    {
        $snapshot = $this->snapshot->values();

        $envelopes = [
            Event::sessionStarted($sessionId, $environment, $expiresAt)->envelope(),
            Event::environmentSnapshot($snapshot)->envelope(),
            Event::environmentConfiguration($snapshot)->envelope(),
        ];

        // The list support compares against a working store when a conflict is
        // suspected; chunked because a store can run more modules than one
        // event has room for.
        foreach (Event::environmentPlugins($this->activeModules->values()) as $event) {
            $envelopes[] = $event->envelope();
        }

        return $envelopes;
    }

    /**
     * Record a start that did not happen, so the merchant can see why.
     *
     * @param array $mapped
     * @param array $response
     * @param int $status
     * @return array
     */
    private function reject(array $mapped, array $response, int $status): array
    {
        $traceId = Event::text((string) $response['trace_id']);
        $requestId = Event::text((string) $response['request_id']);

        $this->session->fail($mapped, $traceId, $requestId);

        $this->logger->error('Paypercut telemetry: mint rejected', [
            'status' => (int) $response['status'],
            'reason_code' => $mapped['reason_code'],
            'trace_id' => $traceId,
            'request_id' => $requestId,
        ]);

        return $this->error(
            [
                'message' => $mapped['message'],
                'reason_code' => $mapped['reason_code'],
                'retryable' => $mapped['retryable'],
                'trace_id' => $traceId,
                'request_id' => $requestId,
            ],
            $status
        );
    }

    /**
     * @param array $data
     * @return array
     */
    private function ok(array $data): array
    {
        $data['queued'] = $this->queue->size();
        $data['now'] = time();

        return [
            'ok' => true,
            'data' => $data,
            'status' => 200,
        ];
    }

    /**
     * @param array $data
     * @param int $status
     * @return array
     */
    private function error(array $data, int $status): array
    {
        return [
            'ok' => false,
            'data' => $data,
            'status' => $status >= 400 && $status < 600 ? $status : 502,
        ];
    }
}
