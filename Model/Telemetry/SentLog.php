<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * A local copy of the events this store actually delivered.
 *
 * The queue is emptied as it drains, so by the time anyone looks there is
 * nothing left to see: the panel could report "37 events sent" and offer no way
 * to find out what they were. Consent to send diagnostics is worth more when
 * the sender can inspect what left.
 *
 * Nothing here runs on a storefront request: {@see Flusher} delivers only from
 * authenticated admin requests, so this write lands where the settings page
 * already writes and the checkout path is untouched.
 */
class SentLog
{
    /**
     * Entries kept before the oldest are discarded.
     *
     * A session is an hour; a busy store can deliver far more than this, so the
     * log is a tail rather than a transcript. The panel says so.
     */
    const MAX_ENTRIES = 100;

    /**
     * Byte ceiling for the stored blob, enforced oldest-first.
     */
    const MAX_BYTES = 131072;

    /**
     * @var Store
     */
    private $store;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Store $store
     * @param Json $json
     */
    public function __construct(Store $store, Json $json)
    {
        $this->store = $store;
        $this->json = $json;
    }

    /**
     * Record envelopes the edge accepted, newest last.
     *
     * @param array $envelopes Exactly what was POSTed.
     * @return void
     */
    public function append(array $envelopes): void
    {
        if (empty($envelopes)) {
            return;
        }

        $entries = array_merge($this->all(), $envelopes);

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        while (count($entries) > 1 && $this->bytes($entries) > self::MAX_BYTES) {
            array_shift($entries);
        }

        $this->store->putBlob(Store::SENT_LOG_KEY, $entries);
    }

    /**
     * @return array
     */
    public function all(): array
    {
        return $this->store->getBlob(Store::SENT_LOG_KEY);
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->store->deleteBlob(Store::SENT_LOG_KEY);
    }

    /**
     * @param array $entries
     * @return int
     */
    private function bytes(array $entries): int
    {
        return strlen($this->json->serialize($entries));
    }
}
