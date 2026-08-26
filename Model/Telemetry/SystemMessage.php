<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;

/**
 * Tells every administrator that this store is currently sending diagnostics.
 *
 * The telemetry permission is held by more than one role, and the module's own
 * logger is gated on a merchant preference, so without this a session could run
 * with no visible trace for anyone but the person who started it.
 */
class SystemMessage implements MessageInterface
{
    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @param TelemetrySession $session
     * @param UrlInterface $urlBuilder
     * @param TimezoneInterface $localeDate
     */
    public function __construct(
        TelemetrySession $session,
        UrlInterface $urlBuilder,
        TimezoneInterface $localeDate
    ) {
        $this->session = $session;
        $this->urlBuilder = $urlBuilder;
        $this->localeDate = $localeDate;
    }

    /**
     * Keyed on the session, so a new session raises a new notice.
     *
     * @return string
     */
    public function getIdentity()
    {
        return 'paypercut_telemetry_' . md5((string) ($this->session->record()['session_id'] ?? ''));
    }

    /**
     * @return bool
     */
    public function isDisplayed()
    {
        $record = $this->session->record();

        return ($record['status'] ?? '') === 'active' && (int) ($record['expires_at'] ?? 0) > time();
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getText()
    {
        $record = $this->session->record();

        return __(
            'Paypercut: a debug session started by %1 is running until %2. <a href="%3">Manage it</a>',
            (string) ($record['started_by_name'] ?? ''),
            $this->endsAt((int) ($record['expires_at'] ?? 0)),
            $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'payment'])
        );
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_NOTICE;
    }

    /**
     * @param int $timestamp
     * @return string
     */
    private function endsAt(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        return $this->localeDate->formatDateTime(
            new \DateTime('@' . $timestamp),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT
        );
    }
}
