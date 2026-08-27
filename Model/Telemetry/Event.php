<?php
declare(strict_types=1);

namespace Paypercut\Payment\Model\Telemetry;

use Paypercut\Payment\Model\Api\PaypercutApiException;
use Throwable;

/**
 * A single diagnostic event, and the allow-list that defines what may leave the store.
 *
 * There is deliberately no generic "record these fields" constructor. Every
 * event is built by a named constructor with declared scalar parameters, so the
 * set of things that can ever be transmitted is fixed at compile time rather
 * than at each call site. `is_scalar()` is explicitly NOT the boundary — every
 * secret this module holds (the card secret key, the BNPL secret key, the
 * webhook secret) is a scalar string living beside the settings we do report.
 */
class Event
{
    /**
     * Longest string any single field may carry, in bytes.
     *
     * Bytes rather than codepoints because the edge bounds the raw Go string:
     * a 128-codepoint CJK theme name is 384 bytes and would be dropped whole.
     */
    const MAX_TEXT_BYTES = 256;

    /**
     * The edge keeps the first attributes in sorted key order and drops the
     * rest, so a single over-wide event would silently lose its version fields.
     */
    const MAX_ATTRS = 16;

    /**
     * Enough frames to see where a failure came from, never a full dump.
     */
    const MAX_STACK_FRAMES = 8;

    /**
     * Shortest run of a credential that still identifies it, for the clipped
     * comparison below. Long enough that ordinary prose cannot collide with it.
     */
    const MIN_SECRET_PREFIX_BYTES = 12;

    /**
     * How deep the deny assertion will walk an envelope before refusing it.
     *
     * The wire shape nests two levels (`error.stack`); the bound only stops a
     * malformed event from recursing without end, and exceeding it is a denial,
     * never a pass.
     */
    const MAX_SCREEN_DEPTH = 6;

    /**
     * Field names that must never appear in an event, whatever their value.
     */
    const DENIED_KEY_PATTERN = '/secret|token|password|credential|nonce|auth|_key$/i';

    /**
     * Value shapes that must never appear in an event, whatever their field name.
     *
     * Matched against the credentials this module actually holds — a Paypercut
     * key or an Ory-issued JWT. Not anchored to the start of the string,
     * because a stack frame or an HTTP error carries the credential mid-string
     * every time; not left unanchored either, because bare `sk_`/`pk_` matches
     * `disk_usage` and `risk_free` and a tripped assertion bins the whole event.
     */
    const DENIED_VALUE_PATTERN = '/(?:^|[^A-Za-z0-9_])(ppc_|sk_|pk_|whsec_|eyJ[A-Za-z0-9_-]+\.)/i';

    /**
     * Host and platform versions. Read by environmentSnapshot().
     *
     * Both snapshot lists are iterated INSTEAD of the caller's array: pulling
     * keys from a settings array is how a credential ends up on the wire.
     *
     * @var array<string, string>
     */
    const SNAPSHOT_FIELDS = [
        'plugin_version' => 'text',
        'magento_version' => 'text',
        'magento_edition' => 'text',
        'php_version' => 'text',
        'theme_name' => 'text',
        'theme_version' => 'text',
        'is_multistore' => 'bool',
        'is_ssl' => 'bool',
    ];

    /**
     * Module settings. Read by environmentConfiguration().
     *
     * @var array<string, string>
     */
    const CONFIGURATION_FIELDS = [
        'checkout_mode' => 'identifier',
        'payment_action' => 'identifier',
        'order_status' => 'identifier',
        'refund_action' => 'identifier',
        'saved_payment_methods' => 'bool',
        'card_enabled' => 'bool',
        'bnpl_enabled' => 'bool',
        'subscriptions_enabled' => 'bool',
        'logging_enabled' => 'bool',
        'connection_environment' => 'identifier',
        'api_key_configured' => 'bool',
        'webhook_configured' => 'bool',
        'bnpl_key_configured' => 'bool',
        'bnpl_installments' => 'text',
        'bnpl_show_installment_preview' => 'bool',
        'subscription_collection_method' => 'identifier',
    ];

    /**
     * @var string
     */
    private $name;

    /**
     * @var array<string, bool|float|int|string>
     */
    private $fields;

    /**
     * Contract-level correlation fields, sent outside `attrs`.
     *
     * @var array<string, string>
     */
    private $correlation = [];

    /**
     * @var array<string, mixed>
     */
    private $error = [];

    /**
     * @param string $name
     * @param array<string, bool|float|int|string> $fields
     */
    private function __construct(string $name, array $fields)
    {
        $this->name = $name;
        $this->fields = $fields;
    }

    /**
     * Report something that happened and did not fail.
     *
     * Failures alone cannot answer the commonest support question, which is
     * whether the shopper ever reached us: a session with no `checkout.*`
     * events at all and one with a silent early return look identical.
     *
     * @param string $name
     * @param array<string, mixed> $attrs
     * @return self
     */
    public static function of(string $name, array $attrs = []): self
    {
        return new self($name, self::cleanAttrs($attrs));
    }

    /**
     * Report a failure, under whichever event name describes where it happened.
     *
     * @param string $name
     * @param string $code
     * @param array<string, mixed> $attrs
     * @param Throwable|null $exception
     * @return self
     */
    public static function failure(string $name, string $code, array $attrs = [], ?Throwable $exception = null): self
    {
        $event = new self($name, self::cleanAttrs($attrs));

        $event->error = ['code' => self::text($code) ?: 'unknown'];

        if ($exception !== null) {
            // An exception message is upstream text: Magento's DB layer quotes
            // the full SQL and `user@host` back, and its LocalizedExceptions
            // carry order totals the disclosure promises are not shared. The
            // type, the scrubbed stack and `origin` carry the diagnosis; a
            // message this module authored is set with because().
            $event->error['type'] = self::shortClassName($exception);
            $event->error['stack'] = self::stack($exception);

            $event->addFields(self::origin(self::frameFiles($exception)));
        }

        return $event;
    }

    /**
     * Report a Paypercut API failure with the fields the platform returned.
     *
     * @param string $name
     * @param PaypercutApiException $exception
     * @param array<string, mixed> $attrs
     * @return self
     */
    public static function apiFailure(string $name, PaypercutApiException $exception, array $attrs = []): self
    {
        $event = self::failure($name, 'http_' . $exception->getStatusCode(), $attrs, $exception);

        // The platform quotes its input back — a rejected key arrives inside
        // the message — so no API prose travels. `api_code` and `trace_id`
        // carry the diagnosis instead.
        unset($event->error['message']);

        $event->error['type'] = self::text($exception->getErrorType()) ?: ($event->error['type'] ?? 'ApiError');

        $api = [];

        foreach ([
            'api_code' => $exception->getErrorCode(),
            'api_param' => $exception->getParam(),
            'trace_id' => $exception->getTraceId(),
        ] as $key => $value) {
            $clean = self::text((string) $value);

            if ($clean !== '') {
                $api[$key] = $clean;
            }
        }

        $api['http_status'] = $exception->getStatusCode();

        $event->addFields($api);

        return $event;
    }

    /**
     * Report the fatal that ended a request.
     *
     * Built from error_get_last(), which carries no exception and no trace —
     * the file that died is the only attribution available.
     *
     * @param string $message
     * @param string $file
     * @param int $line
     * @param int $level
     * @return self
     */
    public static function fatal(string $message, string $file, int $line, int $level): self
    {
        $event = new self('php.fatal', ['level' => $level]);

        $event->addFields(self::origin([$file]));

        $fatal = self::fatalError($message);

        $event->error = [
            'code' => 'php_fatal',
            'type' => $fatal['type'],
            'stack' => [self::relativePath($file) . ':' . $line],
        ];

        if ($fatal['message'] !== '') {
            $event->error['message'] = $fatal['message'];
        }

        return $event;
    }

    /**
     * Note what is absent: the admin user who started the session. The durable
     * record keeps it for the admin notice, but it is a store-user identifier
     * that the merchant-facing disclosure does not cover, so it stays local.
     *
     * @param string $sessionId
     * @param string $environment
     * @param int $expiresAt
     * @return self
     */
    public static function sessionStarted(string $sessionId, string $environment, int $expiresAt): self
    {
        return new self('session.started', [
            'session_id' => self::identifier($sessionId),
            'environment' => self::identifier($environment),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @param string $sessionId
     * @param string $reason
     * @param int $eventsSent
     * @param int $eventsDropped
     * @return self
     */
    public static function sessionStopped(string $sessionId, string $reason, int $eventsSent, int $eventsDropped): self
    {
        return new self('session.stopped', [
            'session_id' => self::identifier($sessionId),
            'reason' => self::identifier($reason),
            'events_sent' => $eventsSent,
            'events_dropped' => $eventsDropped,
        ]);
    }

    /**
     * Build the one-off environment snapshot.
     *
     * @param array<string, mixed> $values Candidate values; only SNAPSHOT_FIELDS keys are read.
     * @return self
     */
    public static function environmentSnapshot(array $values): self
    {
        return new self('environment.snapshot', self::castFields(self::SNAPSHOT_FIELDS, $values));
    }

    /**
     * Build the one-off module-configuration snapshot.
     *
     * Separate from the environment snapshot only because the two together
     * exceed MAX_ATTRS; nothing else distinguishes them.
     *
     * @param array<string, mixed> $values Candidate values; only CONFIGURATION_FIELDS keys are read.
     * @return self
     */
    public static function environmentConfiguration(array $values): self
    {
        return new self('environment.configuration', self::castFields(self::CONFIGURATION_FIELDS, $values));
    }

    /**
     * Build the installed-module inventory, chunked to fit the attribute cap.
     *
     * A conflict is usually named here: this is the list support compares
     * against a working store. Versions only — no author, no path.
     *
     * @param array<string, string> $plugins name => version, sorted by the caller.
     * @return self[]
     */
    public static function environmentPlugins(array $plugins): array
    {
        $total = count($plugins);
        $named = [];
        $quoted = [];

        foreach ($plugins as $slug => $version) {
            $key = self::text((string) $slug);

            if ($key === '') {
                continue;
            }

            // A module NAME can trip the denied-key screen all by itself —
            // `ParadoxLabs_Authnetcim`, `MSP_TwoFactorAuth` — and the assertion
            // drops the whole event, taking its 13 innocent chunk-mates with
            // it. Those names travel as values instead, where only the value
            // screens apply and the inventory survives intact.
            if (preg_match(self::DENIED_KEY_PATTERN, $key)) {
                $quoted[] = $key . ' ' . self::text((string) $version);
                continue;
            }

            $named[$key] = self::text((string) $version);
        }

        $events = [];
        $chunk = 0;

        foreach (array_chunk($named, self::MAX_ATTRS - 2, true) as $slice) {
            $chunk++;
            $events[] = new self('environment.plugins', [
                'plugin_count' => $total,
                'chunk' => $chunk,
            ] + $slice);
        }

        foreach (array_chunk($quoted, self::MAX_ATTRS - 2) as $slice) {
            $chunk++;
            $fields = [
                'plugin_count' => $total,
                'chunk' => $chunk,
            ];

            foreach ($slice as $index => $entry) {
                $fields['module_' . ($index + 1)] = $entry;
            }

            $events[] = new self('environment.plugins', $fields);
        }

        return $events;
    }

    /**
     * Attach the ids that join this event to a payment.
     *
     * @param array<string, mixed> $correlation
     * @return $this
     */
    public function about(array $correlation): self
    {
        foreach (['payment_intent_id', 'payment_id', 'order_ref'] as $field) {
            $value = trim((string) ($correlation[$field] ?? ''));

            if ($value !== '') {
                $this->correlation[$field] = self::text($value);
            }
        }

        return $this;
    }

    /**
     * A message this module authored itself, for a failure with no exception
     * worth quoting.
     *
     * @param string $message
     * @return $this
     */
    public function because(string $message): self
    {
        $clean = self::text($message);

        if ($clean !== '') {
            $this->error['message'] = $clean;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The wire shape of a single event inside a batch.
     *
     * The contract's field is `occurred_at`, an RFC3339 STRING. Sending a unix
     * int under that name fails the whole event, so name and type move together.
     *
     * @param int|null $now Injected clock, so the suite can pin timestamps.
     * @return array<string, mixed>
     */
    public function envelope(?int $now = null): array
    {
        $envelope = [
            'event' => $this->name,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
        ];

        foreach ($this->correlation as $field => $value) {
            $envelope[$field] = $value;
        }

        if (!empty($this->error)) {
            $envelope['error'] = $this->error;
        }

        // PHP renders an empty array as [], which the edge reads as "not an
        // object" and records as a drop against an otherwise clean event.
        if (!empty($this->fields)) {
            $envelope['attrs'] = self::capFields($this->fields);
        }

        return $envelope;
    }

    /**
     * The screen applied to an envelope on its way to the queue.
     *
     * The WHOLE envelope, not a hand-picked subset: the correlation fields
     * written by about() are as much on the wire as `attrs`, and on the webhook
     * paths their value came from an unauthenticated request body. A field
     * added to envelope() tomorrow is screened by construction, because nothing
     * here enumerates field names.
     *
     * @param array<string, mixed> $envelope
     * @param array<int, mixed> $secrets
     * @return bool
     */
    public static function envelopeDenied(array $envelope, array $secrets = []): bool
    {
        return self::isDenied($envelope, $secrets);
    }

    /**
     * Hard deny assertion: true when this event must be dropped entirely.
     *
     * A safety net behind the named constructors, not the primary control. It
     * drops the whole event rather than the offending field, because a field
     * that trips it means the event was assembled wrongly and the rest of it
     * cannot be trusted either.
     *
     * @param array<string, mixed> $fields
     * @param array<int, mixed> $secrets
     * @param int $depth
     * @return bool
     */
    public static function isDenied(array $fields, array $secrets = [], int $depth = 0): bool
    {
        foreach ($fields as $key => $value) {
            if (preg_match(self::DENIED_KEY_PATTERN, (string) $key)) {
                return true;
            }

            // The contract nests one level — `error`, and `error.stack` inside
            // it. Without recursion the assertion sees a non-string and gives
            // up, which is exactly where free text now lives.
            if (is_array($value)) {
                // Deny rather than skip past the bound: a structure the screen
                // cannot finish walking is one it cannot vouch for.
                if ($depth >= self::MAX_SCREEN_DEPTH) {
                    return true;
                }

                if (self::isDenied($value, $secrets, $depth + 1)) {
                    return true;
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            // Anything that is not a scalar cannot be rendered for comparison,
            // so it is denied rather than waved through unread.
            if (!is_scalar($value)) {
                return true;
            }

            // Screen the wire form, not the PHP value: json_encode renders an
            // int or float verbatim, so `4111111111111111` is a PAN on the wire
            // whether or not it was ever a string — and a plain (string) cast
            // would hide the float one behind exponent notation.
            if (!is_string($value)) {
                $encoded = json_encode($value);
                $value = is_string($encoded) ? $encoded : '';
            }

            if ($value === '') {
                continue;
            }

            if (preg_match(self::DENIED_VALUE_PATTERN, $value)) {
                return true;
            }

            if (self::containsCardNumber($value)) {
                return true;
            }

            // Shape matching is a guess; comparing against the store's actual
            // credentials is not. This catches a secret whose format we never
            // anticipated, including one a future Paypercut release introduces.
            foreach ($secrets as $secret) {
                if (!is_string($secret) || $secret === '') {
                    continue;
                }

                if (strpos($value, $secret) !== false || self::endsWithSecretPrefix($value, $secret)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does the value end in the opening of a secret?
     *
     * text() clamps at MAX_TEXT_BYTES before the assertion ever sees the value,
     * so a credential sitting near that boundary reaches here with its tail cut
     * off and the plain strpos() comparison misses it.
     *
     * @param string $value
     * @param string $secret
     * @return bool
     */
    private static function endsWithSecretPrefix(string $value, string $secret): bool
    {
        $longest = min(strlen($value), strlen($secret) - 1);

        for ($length = $longest; $length >= self::MIN_SECRET_PREFIX_BYTES; $length--) {
            if (substr($value, -$length) === substr($secret, 0, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Luhn-valid 13-19 digit run anywhere in the value.
     *
     * The edge screens for a PAN too, but only when the whole value is one:
     * `Card 4111111111111111 was declined` passes it. Card data must never
     * leave a merchant estate, so the client is the right place to enforce it.
     *
     * @param string $value
     * @return bool
     */
    public static function containsCardNumber(string $value): bool
    {
        if (!preg_match_all('/\d(?:[ -]?\d){12,18}/', $value, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            if (self::luhnValid((string) preg_replace('/\D/', '', $candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Free-ish text: printable characters only, hard byte cap.
     *
     * UTF-8 is preserved rather than stripped — a Greek or Japanese theme name
     * is one of the more useful diagnostics there is, and reducing it to an
     * empty string would silently lose it. Only control characters go.
     *
     * @param string $value
     * @return string
     */
    public static function text(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        if ($clean === '' && $value !== '') {
            // Invalid UTF-8 made the unicode-mode replace fail; fall back to ASCII.
            $clean = (string) preg_replace('/[^\x20-\x7E]/', '', $value);
        }

        // mb_strcut cuts on a byte budget while respecting codepoint
        // boundaries; mb_substr counts codepoints and would overshoot the
        // edge's byte bound.
        return function_exists('mb_strcut')
            ? mb_strcut($clean, 0, self::MAX_TEXT_BYTES)
            : substr($clean, 0, self::MAX_TEXT_BYTES);
    }

    /**
     * Identifier-shaped values only; anything else is dropped rather than mangled.
     *
     * @param string $value
     * @return string
     */
    public static function identifier(string $value): string
    {
        // \A/\z rather than ^/$: PCRE's `$` accepts a trailing newline, which
        // would let "pi_1\n" through as identifier-shaped.
        return preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/D', $value) ? $value : '';
    }

    /**
     * The class name without its namespace.
     *
     * Public because a call site that must not send an exception's message
     * still wants to name its type — a rejected credential is quoted back in
     * the message but never in the class.
     *
     * @param Throwable $exception
     * @return string
     */
    public static function shortClassName(Throwable $exception): string
    {
        $parts = explode('\\', get_class($exception));

        return self::text((string) end($parts)) ?: 'Throwable';
    }

    /**
     * Attribute a failure to the code that raised it.
     *
     * The commonest support case is another module breaking ours, and the
     * answer is in the stack: the first frame outside our own directory names
     * it. The wire values stay `plugin`/`theme`/`core`/`paypercut` across every
     * platform so support can compare stores; only merchant-facing copy says
     * "module".
     *
     * @param string[] $files Absolute paths, innermost first.
     * @return array<string, string>
     */
    public static function origin(array $files): array
    {
        $ours = self::moduleRoot();
        $base = self::basePath();

        foreach ($files as $file) {
            $file = (string) $file;

            if ($ours !== '' && strpos($file, $ours) === 0) {
                continue;
            }

            if ($base === '') {
                return ['origin' => 'core'];
            }

            if (strpos($file, $base . '/app/design/') === 0) {
                return ['origin' => 'theme'];
            }

            if (strpos($file, $base . '/app/code/') === 0) {
                $relative = substr($file, strlen($base . '/app/code/'));
                $parts = explode('/', $relative);

                return [
                    'origin' => 'plugin',
                    'origin_plugin' => self::text(implode('_', array_slice($parts, 0, 2))),
                ];
            }

            if (strpos($file, $base . '/vendor/') === 0) {
                $relative = substr($file, strlen($base . '/vendor/'));
                $parts = explode('/', $relative);
                $package = implode('/', array_slice($parts, 0, 2));

                if (($parts[0] ?? '') === 'magento') {
                    return ['origin' => 'core'];
                }

                return [
                    'origin' => 'plugin',
                    'origin_plugin' => self::text($package),
                ];
            }

            return ['origin' => 'core'];
        }

        return ['origin' => 'paypercut'];
    }

    /**
     * @param array<string, string> $schema
     * @param array<string, mixed> $values
     * @return array<string, bool|float|int|string>
     */
    private static function castFields(array $schema, array $values): array
    {
        $fields = [];

        foreach ($schema as $key => $cast) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($cast === 'bool') {
                $fields[$key] = (bool) $value;
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $clean = $cast === 'identifier'
                ? self::identifier((string) $value)
                : self::text((string) $value);

            if ($clean !== '') {
                $fields[$key] = $clean;
            }
        }

        return $fields;
    }

    /**
     * Bound attributes a call site passed in, rather than trusting them.
     *
     * Booleans and ints are already bounded and pass through intact; strings
     * are clamped and control-stripped; anything else is not a scalar
     * diagnostic and is dropped.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, bool|float|int|string>
     */
    private static function cleanAttrs(array $attrs): array
    {
        $fields = [];

        foreach ($attrs as $key => $value) {
            if (count($fields) >= self::MAX_ATTRS) {
                break;
            }

            $name = self::text((string) $key);

            if ($name === '' || !is_scalar($value)) {
                continue;
            }

            $fields[$name] = is_string($value) ? self::text($value) : $value;
        }

        return $fields;
    }

    /**
     * Merge module-authored diagnostic fields in, under the attribute cap.
     *
     * @param array<string, bool|float|int|string> $extra
     * @return void
     */
    private function addFields(array $extra): void
    {
        $this->fields = self::capFields(array_merge($this->fields, $extra), $extra);
    }

    /**
     * Hold a field set to MAX_ATTRS, keeping the fields this module added.
     *
     * The edge keeps attributes in sorted key order and drops the overflow, so
     * an over-wide event loses whichever keys sort last — `origin`, `trace_id`
     * and the version fields among them. Caller-supplied attrs give way first.
     *
     * @param array<string, bool|float|int|string> $fields
     * @param array<string, bool|float|int|string> $keep
     * @return array<string, bool|float|int|string>
     */
    private static function capFields(array $fields, array $keep = []): array
    {
        if (count($fields) <= self::MAX_ATTRS) {
            return $fields;
        }

        $capped = [];

        foreach ($fields as $key => $value) {
            if (count($capped) < self::MAX_ATTRS && array_key_exists($key, $keep)) {
                $capped[$key] = $value;
            }
        }

        foreach ($fields as $key => $value) {
            if (count($capped) >= self::MAX_ATTRS) {
                break;
            }

            if (!array_key_exists($key, $capped)) {
                $capped[$key] = $value;
            }
        }

        return $capped;
    }

    /**
     * File and line only, at most MAX_STACK_FRAMES of them.
     *
     * Never getTraceAsString(): that renders call arguments, which here are
     * checkout payloads and credentials.
     *
     * @param Throwable $exception
     * @return string[]
     */
    private static function stack(Throwable $exception): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            if (count($frames) >= self::MAX_STACK_FRAMES) {
                break;
            }

            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }

            $frames[] = self::relativePath((string) $frame['file']) . ':' . (int) $frame['line'];
        }

        return $frames;
    }

    /**
     * Absolute file paths from a throwable, its own location first.
     *
     * @param Throwable $exception
     * @return string[]
     */
    private static function frameFiles(Throwable $exception): array
    {
        $files = [$exception->getFile()];

        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'])) {
                $files[] = (string) $frame['file'];
            }
        }

        return $files;
    }

    /**
     * Paths relative to the module or the Magento root: an absolute path on
     * shared hosting names the merchant's account or domain.
     *
     * @param string $file
     * @return string
     */
    private static function relativePath(string $file): string
    {
        $base = self::basePath();
        $roots = [self::moduleRoot()];

        if ($base !== '') {
            $roots[] = $base . '/app/code';
            $roots[] = $base . '/app/design';
            $roots[] = $base . '/vendor';
            $roots[] = $base;
        }

        foreach ($roots as $root) {
            if ($root !== '' && strpos($file, $root) === 0) {
                return ltrim(substr($file, strlen($root)), '/');
            }
        }

        return '[external]';
    }

    /**
     * Split PHP's fatal message into a type and the part that may be reported.
     *
     * An uncaught throwable puts ITS OWN message here, which is upstream text
     * under another name — the same prose failure() refuses to send, plus a
     * ValueError's quoted argument values. Only the class survives. An engine
     * fatal (memory exhausted, undefined function) is PHP's own wording and is
     * kept, scrubbed of the inlined trace and of absolute paths.
     *
     * @param string $message
     * @return array{type: string, message: string}
     */
    private static function fatalError(string $message): array
    {
        $message = self::fatalMessage($message);

        if (preg_match('/\AUncaught\s+([A-Za-z0-9_\\\\]+)\s*:/', $message, $matches)) {
            $parts = explode('\\', $matches[1]);

            return ['type' => self::text((string) end($parts)) ?: 'FatalError', 'message' => ''];
        }

        return ['type' => 'FatalError', 'message' => self::text($message)];
    }

    /**
     * Reduce PHP's fatal message to the part that is not already reported.
     *
     * An uncaught Error arrives with its whole stack trace inlined and every
     * path absolute. Left alone it spends the byte clamp on frames the `stack`
     * field already carries, and puts the server's filesystem layout on the wire.
     *
     * @param string $message
     * @return string
     */
    private static function fatalMessage(string $message): string
    {
        $trace = strpos($message, 'Stack trace:');

        if ($trace !== false) {
            $message = rtrim(substr($message, 0, $trace));
        }

        foreach ([self::moduleRoot(), self::basePath()] as $root) {
            if ($root !== '') {
                $message = str_replace(rtrim($root, '/') . '/', '', $message);
            }
        }

        return $message;
    }

    /**
     * @return string
     */
    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return string
     */
    private static function basePath(): string
    {
        return defined('BP') ? rtrim((string) constant('BP'), '/') : '';
    }

    /**
     * @param string $digits
     * @return bool
     */
    private static function luhnValid(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
