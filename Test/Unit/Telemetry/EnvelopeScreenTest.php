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

        $this->assertSame($poison, $envelope[$field], 'the value must actually reach the envelope');
        $this->assertTrue(Event::envelopeDenied($envelope, self::SECRETS));
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
