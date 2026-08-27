<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Api\PaypercutApiException;
use Paypercut\Payment\Model\Telemetry\Event;
use PHPUnit\Framework\TestCase;

/**
 * The screen is applied to the envelope as it will be SENT.
 *
 * These tests enumerate the envelopes from the real constructors rather than
 * naming fields, so a field added to envelope() tomorrow is poisoned and
 * asserted on without anyone remembering to extend this file. That is the whole
 * point: the hole this closes was a hand-picked `['attrs', 'error']` subset
 * that left the correlation fields — fed, on the webhook paths, from an
 * unauthenticated request body — entirely unscreened.
 */
class EnvelopeScreenTest extends TestCase
{
    /**
     * The store's own credentials, as TelemetrySession::credentials() supplies them.
     */
    private const SECRETS = ['ppc_live_store_secret', 'sk_magento_store_api_key_value'];

    /**
     * Values that must never reach the wire, whichever field carries them.
     *
     * The non-string entries are not padding: the envelope is JSON, and
     * json_encode writes an int or a float out as its digits, so a numeric
     * attribute is every bit as much a PAN on the wire as a quoted one.
     *
     * @return array<string, mixed>
     */
    private static function poisons(): array
    {
        return [
            'a Luhn-valid PAN' => '4111111111111111',
            'a PAN in prose' => 'attempt 4111 1111 1111 1111 declined',
            'a store API key' => 'sk_magento_store_api_key_value',
            'a paypercut key' => 'ppc_live_store_secret',
            'a key in prose' => 'rejected ppc_live_store_secret here',
            'a bearer token' => 'bearer eyJhbGciOiJSUzI1NiJ9.body',
            'an integer PAN' => 4111111111111111,
            'a float PAN' => 4111111111111111.0,
            'a nested PAN' => ['note' => '4111111111111111'],
        ];
    }

    /**
     * One envelope per named constructor, correlated and errored where possible.
     *
     * @return array<string, array{0: array}>
     */
    public static function envelopes(): array
    {
        $api = new PaypercutApiException('rejected', 401, 'token_invalid', 'invalid_request_error', 'key', 'da74bc');

        return [
            'a plain event with correlation' => [
                Event::of('checkout.hosted.redirected', ['method' => 'card'])
                    ->about([
                        'order_ref' => '000000123',
                        'payment_id' => 'pay_1',
                        'payment_intent_id' => 'pi_1',
                    ])
                    ->envelope(1787250271),
            ],
            'a failure with an exception' => [
                Event::failure('refund.failed', 'transport', ['source' => 'credit_memo'], new \RuntimeException('boom'))
                    ->because('threw RuntimeException')
                    ->about(['order_ref' => '000000123', 'payment_intent_id' => 'pi_1'])
                    ->envelope(1787250271),
            ],
            'an api failure' => [
                Event::apiFailure('api.request_failed', $api, ['api_context' => 'create_session'])
                    ->about(['payment_id' => 'pay_1'])
                    ->envelope(1787250271),
            ],
            'a fatal' => [
                Event::fatal('Allowed memory size exhausted', __FILE__, 12, 1)->envelope(1787250271),
            ],
            'the session lifecycle' => [
                Event::sessionStarted('sess_1', 'production', 1787250271)->envelope(1787250271),
            ],
            'the environment snapshot' => [
                Event::environmentSnapshot([
                    'plugin_version' => '1.1.0',
                    'magento_version' => '2.4.7',
                    'theme_name' => 'Luma',
                    'is_ssl' => true,
                ])->envelope(1787250271),
            ],
            'the module inventory' => [
                Event::environmentPlugins([
                    'Magento_Sales' => '1.0.0',
                    'ParadoxLabs_Authnetcim' => '6.6.0',
                ])[0]->envelope(1787250271),
            ],
        ];
    }

    /**
     * @dataProvider envelopes
     * @param array $envelope
     */
    public function testACleanEnvelopeSurvives(array $envelope): void
    {
        $this->assertFalse(Event::envelopeDenied($envelope, self::SECRETS));
    }

    /**
     * Every leaf of every envelope, poisoned one at a time.
     *
     * @dataProvider envelopes
     * @param array $envelope
     */
    public function testEveryFieldOfTheEnvelopeIsScreened(array $envelope): void
    {
        $paths = self::leafPaths($envelope);

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            foreach (self::poisons() as $label => $poison) {
                $this->assertTrue(
                    Event::envelopeDenied(self::withValueAt($envelope, $path, $poison), self::SECRETS),
                    sprintf('%s at %s escaped the screen', $label, implode('.', $path))
                );
            }
        }
    }

    /**
     * Every leaf KEY of every envelope, poisoned one at a time.
     *
     * A key is on the wire exactly like a value — `{"attrs":{"4111111111111111":"x"}}`
     * ships the PAN just as surely — and substituting values alone cannot
     * express that, which is how the key screens stayed shape-only.
     *
     * @dataProvider envelopes
     * @param array $envelope
     */
    public function testEveryKeyOfTheEnvelopeIsScreened(array $envelope): void
    {
        $paths = self::leafPaths($envelope);

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            foreach (self::poisons() as $label => $poison) {
                if (!is_string($poison)) {
                    continue;
                }

                $this->assertTrue(
                    Event::envelopeDenied(self::withKeyAt($envelope, $path, $poison), self::SECRETS),
                    sprintf('%s as the key at %s escaped the screen', $label, implode('.', $path))
                );
            }
        }
    }

    /**
     * The same thing through the public API rather than by mutating an array.
     *
     * @dataProvider stringPoisons
     * @param string $poison
     */
    public function testAnAttributeNameCannotSmuggle(string $poison): void
    {
        $envelope = Event::of('webhook.received', [$poison => 'x'])->envelope(0);

        $this->assertTrue(Event::envelopeDenied($envelope, self::SECRETS));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stringPoisons(): array
    {
        $cases = [];

        foreach (self::poisons() as $label => $poison) {
            if (is_string($poison)) {
                $cases[$label] = [$poison];
            }
        }

        return $cases;
    }

    /**
     * The clamp runs before the assertion does, so it must never clip a PAN
     * into something the assertion passes: at 241 filler bytes the pre-fix
     * clamp put 15 of 16 digits on the wire, and 15 digits Luhn-complete to
     * exactly one PAN.
     *
     * @dataProvider clampLeadIns
     * @param int $lead
     */
    public function testAPanStraddlingTheByteClampIsDenied(int $lead): void
    {
        $value = str_repeat('x', $lead) . '4111111111111111';

        foreach ([
            Event::of('api.request_failed', ['note' => $value])->envelope(0),
            Event::failure('refund.failed', 'transport')->because($value)->envelope(0),
        ] as $envelope) {
            $this->assertTrue(
                Event::envelopeDenied($envelope, self::SECRETS),
                sprintf('a PAN starting at byte %d survived the clamp', $lead)
            );
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function clampLeadIns(): array
    {
        $cases = [];

        foreach ([236, 240, 241, 244, 248, 252, 255] as $lead) {
            $cases['lead-in of ' . $lead . ' bytes'] = [$lead];
        }

        return $cases;
    }

    /**
     * The unauthenticated BNPL callback path, with a PAN carrying one extra
     * digit — the shape that walked straight through the maximal-run scan.
     *
     * @dataProvider buriedPans
     * @param string $attemptId
     */
    public function testABuriedPanCannotRideTheWebhookCorrelationId(string $attemptId): void
    {
        $envelope = Event::of('webhook.received', ['type' => 'bnpl_callback'])
            ->about(['payment_id' => $attemptId])
            ->envelope(0);

        $this->assertTrue(Event::envelopeDenied($envelope, self::SECRETS));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function buriedPans(): array
    {
        return [
            'a digit in front' => ['94111111111111111'],
            'a digit behind' => ['41111111111111119'],
            'the PAN alone' => ['4111111111111111'],
        ];
    }

    /**
     * Correlation ids are ids. 256 bytes of chosen text is not one, and an
     * unauthenticated webhook body is where these values come from.
     *
     * @dataProvider hostileCorrelationValues
     * @param string $poison
     */
    public function testCorrelationIdsAreBoundedToAnIdentifierCharset(string $poison): void
    {
        foreach (['order_ref', 'payment_id', 'payment_intent_id'] as $field) {
            $envelope = Event::of('webhook.unresolved')->about([$field => $poison])->envelope(0);

            $this->assertArrayNotHasKey($field, $envelope, $poison . ' reached ' . $field);
            $this->assertStringNotContainsString(
                substr($poison, 0, 8),
                (string) json_encode($envelope),
                $poison . ' reached the wire'
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCorrelationValues(): array
    {
        return [
            'markup' => ['<script>alert(1)</script>'],
            'a url' => ['https://evil.example/collect?a=b'],
            'a bidi override' => ["\u{202E}drowssap"],
            'sql' => ["0'; DROP TABLE sales_order;--"],
            'an email address' => ['jane@example.com'],
            'prose' => ['order for 12 Sunset Road'],
            '256 bytes of text' => [str_repeat('A', 256)],
        ];
    }

    /**
     * The references this plugin actually builds must survive lossless — a
     * Magento increment id, its store-prefixed and merchant-shaped forms, and
     * the platform ids that ride beside them.
     *
     * @dataProvider realReferences
     * @param string $reference
     */
    public function testRealReferencesAreLossless(string $reference): void
    {
        $envelope = Event::of('checkout.hosted.redirected')
            ->about(['order_ref' => $reference, 'payment_id' => $reference])
            ->envelope(0);

        $this->assertSame($reference, $envelope['order_ref']);
        $this->assertFalse(Event::envelopeDenied($envelope, self::SECRETS));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realReferences(): array
    {
        return [
            'a default increment id' => ['000000123'],
            'a store-prefixed increment id' => ['2000000047'],
            'a merchant-shaped reference' => ['MAG-2026/8891'],
            'a dashed reference' => ['ORD-000012'],
            'a payment intent id' => ['pi_3PabcDEF12345'],
            'a charge id' => ['ch_1P2abcDEF'],
            'a bnpl attempt uuid' => ['a1b2c3d4-e5f6-4711-8899-aabbccddeeff'],
            'a scoped reference' => ['store2:000000456'],
        ];
    }

    /**
     * The correlation fields specifically — the ones the old screen missed, and
     * the ones an unauthenticated webhook body reaches.
     *
     * @dataProvider correlationFields
     * @param string $field
     * @param string $poison
     */
    public function testCorrelationFieldsCannotSmuggle(string $field, string $poison): void
    {
        $envelope = Event::of('webhook.unresolved')->about([$field => $poison])->envelope(0);

        // Two outcomes are acceptable and no third one is: the value is not
        // id-shaped and never lands, or it lands and the assertion denies it.
        if (array_key_exists($field, $envelope)) {
            $this->assertTrue(Event::envelopeDenied($envelope, self::SECRETS));

            return;
        }

        $this->assertStringNotContainsString(substr($poison, 0, 8), (string) json_encode($envelope));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function correlationFields(): array
    {
        $cases = [];

        // about() casts to string, so only the string poisons survive the trip
        // into a correlation field as themselves.
        foreach (['order_ref', 'payment_id', 'payment_intent_id'] as $field) {
            foreach (self::poisons() as $label => $poison) {
                if (!is_string($poison)) {
                    continue;
                }

                $cases[$field . ': ' . $label] = [$field, $poison];
            }
        }

        return $cases;
    }

    /**
     * A merchant's own order reference is still theirs — the screen drops
     * secrets and card numbers, it does not reshape identifiers.
     */
    public function testAnOrdinaryOrderReferenceIsUntouched(): void
    {
        $envelope = Event::of('checkout.hosted.redirected')->about(['order_ref' => 'MAG-2026/8891'])->envelope(0);

        $this->assertSame('MAG-2026/8891', $envelope['order_ref']);
        $this->assertFalse(Event::envelopeDenied($envelope, self::SECRETS));
    }

    /**
     * The queue must hand the WHOLE envelope to the screen. A future edit that
     * goes back to screening a named subset reopens the smuggle path, and no
     * amount of testing Event::envelopeDenied() would notice.
     */
    public function testTheQueueScreensTheWholeEnvelope(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Model/Telemetry/EventQueue.php');

        $this->assertStringContainsString('Event::envelopeDenied($envelope, $secrets)', $source);
        $this->assertStringNotContainsString("['attrs', 'error']", $source);
    }

    /**
     * A credential clipped by the byte clamp is still a credential.
     */
    public function testASecretCutByTheTextClampIsStillCaught(): void
    {
        $secret = 'ppcsecret_' . str_repeat('a', 40);
        $note = str_repeat('x', Event::MAX_TEXT_BYTES - 20) . substr($secret, 0, 30);

        $envelope = Event::of('api.request_failed', ['note' => $note])->envelope(0);

        $this->assertTrue(Event::envelopeDenied($envelope, [$secret]));
    }

    /**
     * Every scalar leaf, as a list of key paths.
     *
     * @param array $value
     * @param array $prefix
     * @return array<int, array<int, string|int>>
     */
    private static function leafPaths(array $value, array $prefix = []): array
    {
        $paths = [];

        foreach ($value as $key => $child) {
            $path = array_merge($prefix, [$key]);

            if (is_array($child)) {
                $paths = array_merge($paths, self::leafPaths($child, $path));

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * The same leaf, renamed rather than rewritten.
     *
     * @param array $envelope
     * @param array<int, string|int> $path
     * @param string $key
     * @return array
     */
    private static function withKeyAt(array $envelope, array $path, string $key): array
    {
        $leaf = array_pop($path);
        $cursor = &$envelope;

        foreach ($path as $step) {
            $cursor = &$cursor[$step];
        }

        $value = $cursor[$leaf];
        unset($cursor[$leaf]);
        $cursor[$key] = $value;
        unset($cursor);

        return $envelope;
    }

    /**
     * @param array $envelope
     * @param array<int, string|int> $path
     * @param mixed $value
     * @return array
     */
    private static function withValueAt(array $envelope, array $path, $value): array
    {
        $cursor = &$envelope;

        foreach ($path as $key) {
            $cursor = &$cursor[$key];
        }

        $cursor = $value;
        unset($cursor);

        return $envelope;
    }
}
