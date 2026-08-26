<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The three storage primitives the telemetry code needs, and nothing else.
 *
 * - The session record lives in `core_config_data`, which ScopeConfig already
 *   loads and caches on every request, so the storefront gate costs nothing.
 *   Its path is deliberately absent from `system.xml`, so a settings save can
 *   never author it.
 * - Runtime counters, the queue, the inflight batch and the sent log live in
 *   the `flag` table. They must survive a cache flush: losing the merchant's
 *   diagnostics mid-session is the one failure a debug session cannot tolerate.
 * - Locks use Magento's own lock manager, which is a real database lock and is
 *   released when the connection closes.
 */
class Store
{
    const RECORD_CONFIG_PATH = 'payment/paypercut_card/telemetry_session';

    const TOKEN_KEY = 'paypercut_telemetry_token';
    const QUEUE_KEY = 'paypercut_telemetry_queue';
    const INFLIGHT_KEY = 'paypercut_telemetry_inflight';
    const RUNTIME_KEY = 'paypercut_telemetry_runtime';
    const SENT_LOG_KEY = 'paypercut_telemetry_sent_log';
    const START_LOCK = 'paypercut_telemetry_start_lock';
    const FLUSH_LOCK = 'paypercut_telemetry_flush_lock';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var ReinitableConfigInterface
     */
    private $reinitableConfig;

    /**
     * @var FlagManager
     */
    private $flagManager;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param FlagManager $flagManager
     * @param LockManagerInterface $lockManager
     * @param Json $json
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        ReinitableConfigInterface $reinitableConfig,
        FlagManager $flagManager,
        LockManagerInterface $lockManager,
        Json $json
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->reinitableConfig = $reinitableConfig;
        $this->flagManager = $flagManager;
        $this->lockManager = $lockManager;
        $this->json = $json;
    }

    /**
     * The durable session record, or [] when none was ever written.
     *
     * @return array
     */
    public function getRecord(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::RECORD_CONFIG_PATH);

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array $record
     * @return void
     */
    public function putRecord(array $record): void
    {
        $this->configWriter->save(self::RECORD_CONFIG_PATH, $this->json->serialize($record));

        // The write bypassed the cached config the storefront gate reads, and a
        // session that is live in the database but invisible in the cache would
        // send nothing. Session starts and stops are rare enough to pay for it.
        $this->reinitableConfig->reinit();
    }

    /**
     * @return void
     */
    public function deleteRecord(): void
    {
        $this->configWriter->delete(self::RECORD_CONFIG_PATH);
        $this->reinitableConfig->reinit();
    }

    /**
     * A durable blob that is never eagerly loaded.
     *
     * @param string $key
     * @param array $default
     * @return array
     */
    public function getBlob(string $key, array $default = []): array
    {
        $value = $this->flagManager->getFlagData($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * Writing an empty array deletes the key, so an empty queue leaves no row.
     *
     * @param string $key
     * @param array $value
     * @return void
     */
    public function putBlob(string $key, array $value): void
    {
        if (empty($value)) {
            $this->deleteBlob($key);
            return;
        }

        $this->flagManager->saveValue($key, $value);
    }

    /**
     * @param string $key
     * @return void
     */
    public function deleteBlob(string $key): void
    {
        $this->flagManager->deleteFlag($key);
    }

    /**
     * A blob with a deadline. Null when absent or expired.
     *
     * The deadline is a backstop, never the authority: every read re-validates
     * against the session record.
     *
     * @param string $key
     * @return array|null
     */
    public function getExpiring(string $key): ?array
    {
        $stored = $this->flagManager->getFlagData($key);

        if (!is_array($stored) || !isset($stored['expires_at'], $stored['value'])) {
            return null;
        }

        if ((int) $stored['expires_at'] <= time()) {
            return null;
        }

        return is_array($stored['value']) ? $stored['value'] : null;
    }

    /**
     * @param string $key
     * @param array $value
     * @param int $ttlSeconds
     * @return void
     */
    public function putExpiring(string $key, array $value, int $ttlSeconds): void
    {
        if (empty($value)) {
            $this->deleteExpiring($key);
            return;
        }

        $this->flagManager->saveValue($key, [
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ]);
    }

    /**
     * @param string $key
     * @return void
     */
    public function deleteExpiring(string $key): void
    {
        $this->flagManager->deleteFlag($key);
    }

    /**
     * Does a stored expiring blob still exist, whatever it holds?
     *
     * @param string $key
     * @return bool
     */
    public function hasExpiring(string $key): bool
    {
        return $this->flagManager->getFlagData($key) !== null;
    }

    /**
     * Take a lock that genuinely fails under contention.
     *
     * Magento's lock manager is a real database lock, so exactly one concurrent
     * caller wins — unlike a read-then-write, where two clicks in two tabs both
     * mint and one fully valid credential ends up referenced by nothing.
     *
     * @param string $name
     * @return bool
     */
    public function claimLock(string $name): bool
    {
        try {
            return $this->lockManager->lock($name, 0);
        } catch (AlreadyExistsException $exception) {
            return false;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * No-op unless this process still owns the lock.
     *
     * @param string $name
     * @return void
     */
    public function releaseLock(string $name): void
    {
        try {
            $this->lockManager->unlock($name);
        } catch (\Exception $exception) {
            return;
        }
    }
}
