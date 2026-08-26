<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use Paypercut\Payment\Model\Support\Environment;
use Psr\Log\LoggerInterface;

/**
 * Owns the debug (telemetry) session: its state, its storage, and its teardown.
 *
 * A debug session is a merchant-granted, self-expiring window during which the
 * store may send diagnostic events to Paypercut. The deadline is an absolute
 * unix timestamp in a durable, cheaply-readable record, and every read
 * recomputes liveness against it. That is what makes the session end on time
 * with no scheduled job: there is no timer to miss and nothing to orphan if the
 * process dies. The token's own `exp` is the matching bound on the server side.
 */
class TelemetrySession
{
    /**
     * Hard ceiling on a session, independent of what the mint hands back.
     *
     * With no revocation anywhere, this ceiling IS the consent: the merchant is
     * told "about 60 minutes", so the module must not run for longer even if a
     * future deployment issues longer-lived tokens.
     */
    const SESSION_MAX_SECONDS = 3600;

    /**
     * Give up this many seconds before the token actually expires, so the
     * module always stops before the edge would start rejecting it.
     */
    const SKEW_SECONDS = 30;

    const MIN_LIFETIME_SECONDS = 60;

    const MAX_QUEUE_EVENTS = 200;

    const MAX_QUEUE_BYTES = 65536;

    /**
     * Kept well under the edge's 64 KiB body cap: the edge does not
     * deduplicate, so a bigger batch only means losing more events per failed
     * POST.
     */
    const MAX_BATCH_BYTES = 16384;

    /**
     * The edge's own MaxEventsPerBatch. A batch over it is refused with 413.
     */
    const MAX_BATCH_EVENTS = 50;

    const MAX_CONSECUTIVE_SEND_FAILURES = 4;

    const MINT_TIMEOUT_SECONDS = 10;

    const MINT_CONNECT_TIMEOUT_SECONDS = 5;

    const EDGE_TIMEOUT_SECONDS = 5;

    const EDGE_CONNECT_TIMEOUT_SECONDS = 3;

    const FAILED_NOTICE_TTL = 600;

    const ENDED_NOTICE_TTL = 86400;

    const POLL_INTERVAL_SECONDS = 60;

    const SLOW_REQUEST_MS = 3000;

    const CONFIG_PATH_SECRET_KEY = 'payment/paypercut_card/secret_key';

    const CONFIG_PATH_BNPL_SECRET_KEY = 'payment/paypercut_bnpl/secret_key';

    const CONFIG_PATH_WEBHOOK_SECRET = 'payment/paypercut_card/webhook_secret';

    /**
     * Per-request memo for the storefront gate.
     *
     * @var bool|null
     */
    private $activeMemo;

    /**
     * @var Store
     */
    private $store;

    /**
     * @var SentLog
     */
    private $sentLog;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

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
     * @param Store $store
     * @param SentLog $sentLog
     * @param Environment $environment
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param Random $random
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Store $store,
        SentLog $sentLog,
        Environment $environment,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        Random $random,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->store = $store;
        $this->sentLog = $sentLog;
        $this->environment = $environment;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->random = $random;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * The storefront gate: is a session live right now?
     *
     * Reads one already-cached config value and nothing else — no extra
     * queries, no writes, no HTTP. This runs on anonymous checkout requests, so
     * anything more expensive belongs behind an admin guard.
     *
     * @return bool
     */
    public function isActiveFast(): bool
    {
        if ($this->activeMemo !== null) {
            return $this->activeMemo;
        }

        $record = $this->record();

        $this->activeMemo = ($record['status'] ?? '') === 'active'
            && (int) ($record['expires_at'] ?? 0) > time();

        return $this->activeMemo;
    }

    /**
     * Forget the per-request memo. Only state transitions need this.
     *
     * @return void
     */
    public function flushMemo(): void
    {
        $this->activeMemo = null;
    }

    /**
     * @return array
     */
    public function record(): array
    {
        return $this->store->getRecord();
    }

    /**
     * The session state as the admin UI should present it.
     *
     * @return array
     */
    public function describe(): array
    {
        $record = $this->record();
        $runtime = $this->runtime();
        $now = time();

        $status = (string) ($record['status'] ?? '');
        $state = 'idle';

        if ($status === 'active') {
            $state = (int) ($record['expires_at'] ?? 0) > $now ? 'running' : 'ended';
        } elseif ($status === 'failed') {
            $state = ($now - (int) ($record['ended_at'] ?? 0)) < self::FAILED_NOTICE_TTL ? 'failed' : 'idle';
        } elseif ($status === 'stopped' || $status === 'expired') {
            $state = ($now - (int) ($record['ended_at'] ?? 0)) < self::ENDED_NOTICE_TTL ? 'ended' : 'idle';
        }

        return [
            'state' => $state,
            'session_id' => (string) ($record['session_id'] ?? ''),
            'expires_at' => (int) ($record['expires_at'] ?? 0),
            'started_at' => (int) ($record['started_at'] ?? 0),
            'ended_at' => (int) ($record['ended_at'] ?? 0),
            'started_by_name' => (string) ($record['started_by_name'] ?? ''),
            'reason_code' => (string) ($record['reason_code'] ?? ''),
            'trace_id' => (string) ($record['trace_id'] ?? ''),
            'request_id' => (string) ($record['request_id'] ?? ''),
            'retryable' => (bool) ($record['retryable'] ?? false),
            'message' => (string) ($record['message'] ?? ''),
            'events_sent' => (int) ($runtime['events_sent'] ?? 0),
            'events_dropped' => (int) ($runtime['events_dropped'] ?? 0),
        ];
    }

    /**
     * The telemetry token, or '' when there is not a usable one.
     *
     * Every condition here is a reason the token must not be used, and each is
     * checked rather than assumed: the stored deadline is a backstop, never the
     * authority.
     *
     * @return string
     */
    public function token(): string
    {
        $record = $this->record();

        if (($record['status'] ?? '') !== 'active') {
            return '';
        }

        $expiresAt = (int) ($record['expires_at'] ?? 0);

        if ($expiresAt <= time()) {
            return '';
        }

        $stored = $this->store->getExpiring(Store::TOKEN_KEY);

        if (!is_array($stored) || !isset($stored['token']) || !is_string($stored['token'])) {
            return '';
        }

        if ((int) ($stored['expires_at'] ?? 0) !== $expiresAt) {
            return '';
        }

        if (!$this->credentialMatches($record)) {
            return '';
        }

        $decoded = base64_decode($stored['token'], true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Does the stored record still describe the connection the store has today?
     *
     * @param array $record
     * @return bool
     */
    public function credentialMatches(array $record): bool
    {
        $connection = $this->connection();
        $fingerprint = $this->fingerprint($connection['secret']);

        if ($fingerprint === '' || $fingerprint !== (string) ($record['key_fingerprint'] ?? '')) {
            return false;
        }

        return $connection['environment'] === (string) ($record['environment'] ?? '');
    }

    /**
     * The card gateway's stored credential and environment.
     *
     * @return array
     */
    public function connection(): array
    {
        return [
            'secret' => (string) $this->scopeConfig->getValue(self::CONFIG_PATH_SECRET_KEY),
            'environment' => $this->environment->getEnvironment(),
        ];
    }

    /**
     * Every credential the store holds, for the deny assertion to compare against.
     *
     * This list must enumerate every credential-bearing setting: comparing a
     * value against the actual secret is the only screen that catches a format
     * nobody anticipated, and it is silently useless for a setting not named
     * here. A future gateway adding its own credential breaks it.
     *
     * @return string[]
     */
    public function credentials(): array
    {
        $secrets = [$this->token()];

        foreach ([self::CONFIG_PATH_SECRET_KEY, self::CONFIG_PATH_BNPL_SECRET_KEY] as $path) {
            $secrets[] = (string) $this->scopeConfig->getValue($path);
        }

        $secrets[] = $this->webhookSecret();

        // An empty secret would match every string, so the list is filtered.
        return array_values(array_filter($secrets, static function ($secret) {
            return is_string($secret) && $secret !== '';
        }));
    }

    /**
     * The stored webhook secret, decrypted.
     *
     * @return string
     */
    public function webhookSecret(): string
    {
        $stored = (string) $this->scopeConfig->getValue(self::CONFIG_PATH_WEBHOOK_SECRET);

        if ($stored === '') {
            return '';
        }

        try {
            return (string) $this->encryptor->decrypt($stored);
        } catch (\Exception $exception) {
            return '';
        }
    }

    /**
     * A short, non-reversing marker for "the same API key as before".
     *
     * @param string $secret
     * @return string
     */
    public function fingerprint(string $secret): string
    {
        return $secret === '' ? '' : substr(hash('sha256', $secret), 0, 12);
    }

    /**
     * A fresh session identifier.
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function newSessionId(): string
    {
        return 'dbg_' . $this->random->getRandomString(16, Random::CHARS_DIGITS . Random::CHARS_LOWERS);
    }

    /**
     * Publish a new session and store its token.
     *
     * @param array $record
     * @param string $jwt
     * @return void
     */
    public function begin(array $record, string $jwt): void
    {
        $expiresAt = (int) $record['expires_at'];

        $this->store->putExpiring(
            Store::TOKEN_KEY,
            [
                'token' => base64_encode($jwt),
                'expires_at' => $expiresAt,
            ],
            max(60, $expiresAt - time())
        );

        // Never inherit a previous session's buffer: those events were gathered
        // under a different consent and would ship under this session's id.
        $this->store->deleteExpiring(Store::QUEUE_KEY);
        $this->store->deleteExpiring(Store::INFLIGHT_KEY);

        // The log shows what this session sent, so a previous one's tail would
        // misattribute events the merchant is reading to decide what happened.
        $this->sentLog->clear();

        $this->store->putRecord($record);

        $this->store->putBlob(Store::RUNTIME_KEY, [
            'events_sent' => 0,
            'events_dropped' => 0,
            'consecutive_edge_failures' => 0,
            'next_attempt_at' => 0,
            'last_error' => '',
        ]);

        $this->flushMemo();
    }

    /**
     * Record a start that never happened, so the merchant sees why.
     *
     * @param array $mapped
     * @param string $traceId
     * @param string $requestId
     * @return void
     */
    public function fail(array $mapped, string $traceId = '', string $requestId = ''): void
    {
        // Never overwrite a live session with a failure notice: a concurrent
        // start that loses a race would otherwise erase the winner's record and
        // strand its token beyond the reach of every teardown path.
        if (($this->record()['status'] ?? '') === 'active') {
            return;
        }

        $this->store->putRecord([
            'status' => 'failed',
            'ended_at' => time(),
            'reason_code' => $mapped['reason_code'],
            'message' => $mapped['message'],
            'retryable' => $mapped['retryable'],
            'trace_id' => $traceId,
            'request_id' => $requestId,
        ]);

        $this->flushMemo();
    }

    /**
     * End the session and destroy every trace of its credential.
     *
     * Idempotent, and the single teardown path: expiry, the Stop button, a
     * re-key, an environment change and uninstall all arrive here, so there is
     * exactly one place that can forget something.
     *
     * @param string $reason
     * @return void
     */
    public function end(string $reason): void
    {
        $record = $this->record();

        $this->store->deleteExpiring(Store::TOKEN_KEY);
        $this->store->deleteExpiring(Store::QUEUE_KEY);
        $this->store->deleteExpiring(Store::INFLIGHT_KEY);

        if (empty($record) || ($record['status'] ?? '') !== 'active') {
            $this->store->deleteBlob(Store::RUNTIME_KEY);
            $this->flushMemo();
            return;
        }

        $runtime = $this->runtime();

        $this->store->putRecord([
            'status' => $reason === 'expired' ? 'expired' : 'stopped',
            'session_id' => (string) ($record['session_id'] ?? ''),
            'environment' => (string) ($record['environment'] ?? ''),
            'started_at' => (int) ($record['started_at'] ?? 0),
            'expires_at' => (int) ($record['expires_at'] ?? 0),
            'started_by' => (int) ($record['started_by'] ?? 0),
            'started_by_name' => (string) ($record['started_by_name'] ?? ''),
            'ended_at' => time(),
            'reason_code' => $reason,
            'events_sent' => (int) ($runtime['events_sent'] ?? 0),
            'events_dropped' => (int) ($runtime['events_dropped'] ?? 0),
        ]);

        $this->store->deleteBlob(Store::RUNTIME_KEY);

        $this->flushMemo();

        $this->audit('Telemetry: debug session ended', [
            'session_id' => (string) ($record['session_id'] ?? ''),
            'reason' => $reason,
        ]);
    }

    /**
     * Tear down a session whose deadline has passed, or whose connection changed.
     *
     * Admin context only — it writes. This is what turns "the gate is closed"
     * into "the token is gone": the gate flips the instant the deadline passes,
     * but the stored copy is removed by the next admin request that runs this.
     *
     * @return void
     */
    public function reap(): void
    {
        $record = $this->record();

        if (($record['status'] ?? '') !== 'active') {
            // No live session, but a stored token means the record was lost
            // without one. The credential is now referenced by nothing, so
            // destroy it here rather than leave it to expire.
            if ($this->store->hasExpiring(Store::TOKEN_KEY)) {
                $this->end('token_orphaned');
            }

            return;
        }

        if ((int) ($record['expires_at'] ?? 0) <= time()) {
            $this->end('expired');
            return;
        }

        if (!$this->credentialMatches($record)) {
            $this->end('connection_changed');
            return;
        }

        if ($this->token() === '') {
            $this->end('token_lost');
        }
    }

    /**
     * @return array
     */
    public function runtime(): array
    {
        return $this->store->getBlob(Store::RUNTIME_KEY);
    }

    /**
     * @param array $values
     * @return void
     */
    public function updateRuntime(array $values): void
    {
        $this->store->putBlob(Store::RUNTIME_KEY, array_merge($this->runtime(), $values));
    }

    /**
     * Claim an exclusive right to mint.
     *
     * Without a real mutex, two clicks in two tabs both mint. The loser's token
     * is then either overwritten in storage or discarded by the re-check — and
     * either way one fully valid credential exists that no teardown path knows
     * about and nothing can revoke.
     *
     * @return bool
     */
    public function claimStartLock(): bool
    {
        return $this->store->claimLock(Store::START_LOCK);
    }

    /**
     * @return void
     */
    public function releaseStartLock(): void
    {
        $this->store->releaseLock(Store::START_LOCK);
    }

    /**
     * @return bool
     */
    public function claimFlushLock(): bool
    {
        return $this->store->claimLock(Store::FLUSH_LOCK);
    }

    /**
     * @return void
     */
    public function releaseFlushLock(): void
    {
        $this->store->releaseLock(Store::FLUSH_LOCK);
    }

    /**
     * Write a log line whatever the merchant's Debug Mode preference is.
     *
     * Starting and stopping a session is an audit event: a store with the
     * module's own debug logging switched off must still leave a record that
     * data left it. Magento's logger is not gated on that preference.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function audit(string $message, array $context = []): void
    {
        $this->logger->info($message . ' ' . $this->json->serialize($context));
    }
}
