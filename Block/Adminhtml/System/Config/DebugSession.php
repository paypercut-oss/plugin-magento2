<?php
declare(strict_types=1);

namespace Paypercut\Payment\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use Paypercut\Payment\Model\Telemetry\SentLog;
use Paypercut\Payment\Model\Telemetry\TelemetrySession;

/**
 * The debug session panel, in all four of its states.
 *
 * The server paints the current state so the panel is correct with no round
 * trip; the script then keeps the countdown and counters live.
 */
class DebugSession extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Paypercut_Payment::system/config/debug-session.phtml';

    /**
     * @var TelemetrySession
     */
    private $session;

    /**
     * @var SentLog
     */
    private $sentLog;

    /**
     * @var FormKey
     */
    // Named apart from the parent's own $formKey: Magento\Backend\Block\Template
    // declares that protected, and PHP forbids a child narrowing it.
    private $formKeyProvider;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var array|null
     */
    private $state;

    /**
     * @param Context $context
     * @param TelemetrySession $session
     * @param SentLog $sentLog
     * @param FormKey $formKey
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        Context $context,
        TelemetrySession $session,
        SentLog $sentLog,
        FormKey $formKey,
        Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->session = $session;
        $this->sentLog = $sentLog;
        $this->formKeyProvider = $formKey;
        $this->json = $json;
    }

    /**
     * Render the panel across the full width of the config form.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return '<tr id="row_' . $element->getHtmlId() . '"><td colspan="5">' . $this->toHtml() . '</td></tr>';
    }

    /**
     * @return array
     */
    public function getState(): array
    {
        if ($this->state === null) {
            $this->session->reap();
            $this->state = $this->session->describe();
        }

        return $this->state;
    }

    /**
     * @return array
     */
    public function getSentLogEntries(): array
    {
        return $this->sentLog->all();
    }

    /**
     * @return int
     */
    public function getMaxLogEntries(): int
    {
        return SentLog::MAX_ENTRIES;
    }

    /**
     * The time a running session ends, in the admin's own timezone.
     *
     * @return string
     */
    public function getEndsAt(): string
    {
        $state = $this->getState();

        if ($state['expires_at'] <= 0) {
            return '';
        }

        return $this->_localeDate->formatDateTime(
            (new \DateTime('@' . $state['expires_at'])),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * Configuration handed to the panel script.
     *
     * @return string
     */
    public function getJsConfig(): string
    {
        // The payload is emitted unescaped inside a <script> block, where a
        // literal '<' from any value would close it early.
        return str_replace(
            ['<', '>', '&'],
            ['\u003C', '\u003E', '\u0026'],
            (string) $this->json->serialize([
                'startUrl' => $this->getUrl('paypercut/telemetry/start'),
                'stopUrl' => $this->getUrl('paypercut/telemetry/stop'),
                'statusUrl' => $this->getUrl('paypercut/telemetry/status'),
                'formKey' => $this->formKeyProvider->getFormKey(),
                'pollSeconds' => TelemetrySession::POLL_INTERVAL_SECONDS,
                'now' => time(),
                'initial' => $this->getState(),
                'i18n' => [
                    'starting' => (string) __('Starting…'),
                    'startSession' => (string) __('Start session'),
                    'stopping' => (string) __('Stopping…'),
                    'stopNow' => (string) __('Stop now'),
                    'copied' => (string) __('Copied'),
                    'sessionEnded' => (string) __('Debug session ended. Paypercut stops receiving data from this store.'),
                    'networkError' => (string) __('The request could not be completed. Please try again.'),
                    'adminUnreachable' => (string) __('This page cannot reach the store admin any more. Reload the page to see the current state.'),
                ],
            ])
        );
    }

    /**
     * One line summarising an event, so the table is scannable without the JSON.
     *
     * @param array $entry A delivered envelope.
     * @return string
     */
    public function getEventDetail(array $entry): string
    {
        $parts = [];
        $error = isset($entry['error']) && is_array($entry['error']) ? $entry['error'] : [];

        if (isset($error['code'])) {
            $parts[] = (string) $error['code'];
        }

        foreach (['order_ref', 'payment_id', 'payment_intent_id'] as $key) {
            if (!empty($entry[$key])) {
                $parts[] = $key . '=' . (string) $entry[$key];
            }
        }

        $attrs = isset($entry['attrs']) && is_array($entry['attrs']) ? $entry['attrs'] : [];

        foreach (['origin_plugin', 'http_status', 'reason', 'webhook'] as $key) {
            if (isset($attrs[$key]) && is_scalar($attrs[$key])) {
                $parts[] = $key . '=' . (string) $attrs[$key];
            }
        }

        // Lifecycle events carry none of the keys above, and a row of dashes
        // tells the merchant nothing. Fall back to whatever the event does have.
        if (empty($parts)) {
            foreach ($attrs as $key => $value) {
                if (count($parts) >= 3) {
                    break;
                }

                if (is_scalar($value)) {
                    $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                }
            }
        }

        return empty($parts) ? '—' : implode(' · ', $parts);
    }

    /**
     * @param array $entries
     * @return string
     */
    public function getPrettyJson(array $entries): string
    {
        return (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
