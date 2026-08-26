<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Api\PaypercutApiException;
use Paypercut\Payment\Model\Telemetry\Event;
use PHPUnit\Framework\TestCase;

/**
 * The named constructors are the boundary; these pin what they let through.
 */
class EventPrivacyTest extends TestCase
{
    public function testSnapshotWalksItsOwnSchemaAndNotTheCallersArray(): void
    {
        $envelope = Event::environmentSnapshot([
            'plugin_version' => '1.1.3',
            'secret_key' => 'ppc_live_store_secret',
            'webhook_secret' => 'whsec_abc',
        ])->envelope(0);

        $this->assertSame(['plugin_version' => '1.1.3'], $envelope['attrs']);
    }

    public function testConfigurationSnapshotReportsPresenceNotValues(): void
    {
        $envelope = Event::environmentConfiguration([
            'webhook_configured' => true,
            'webhook_secret' => 'whsec_abc',
        ])->envelope(0);

        $this->assertSame(['webhook_configured' => true], $envelope['attrs']);
    }

    public function testApiFailureDropsUpstreamProseAndKeepsTheDiagnosis(): void
    {
        $exception = new PaypercutApiException(
            "The provided access token 'ppc_live_store_secret' is invalid.",
            401,
            'token_invalid',
            'invalid_request_error',
            '',
            'da74bc'
        );

        $envelope = Event::apiFailure('api.request_failed', $exception, ['api_context' => 'create_checkout'])
            ->envelope(0);

        $this->assertArrayNotHasKey('message', $envelope['error']);
        $this->assertSame('http_401', $envelope['error']['code']);
        $this->assertSame('invalid_request_error', $envelope['error']['type']);
        $this->assertSame('token_invalid', $envelope['attrs']['api_code']);
        $this->assertSame('da74bc', $envelope['attrs']['trace_id']);
        $this->assertSame(401, $envelope['attrs']['http_status']);
    }

    public function testAuthoredMessagesSurvive(): void
    {
        $envelope = Event::failure('webhook.registration_failed', 'rejected')
            ->because('threw RuntimeException')
            ->envelope(0);

        $this->assertSame('threw RuntimeException', $envelope['error']['message']);
    }

    public function testTextIsClampedOnBytesAndKeepsUtf8(): void
    {
        $this->assertSame('Θέμα Ελλάδα', Event::text("Θέμα\x00 Ελλάδα"));
        $this->assertLessThanOrEqual(Event::MAX_TEXT_BYTES, strlen(Event::text(str_repeat('ä', 400))));
    }

    public function testIdentifierDropsRatherThanMangles(): void
    {
        $this->assertSame('dbg_abc.123', Event::identifier('dbg_abc.123'));
        $this->assertSame('', Event::identifier('jane@example.com'));
        $this->assertSame('', Event::identifier('12 Sunset Road'));
        $this->assertSame('', Event::identifier(str_repeat('a', 65)));
    }

    public function testAttrsKeepScalarTypesAndDropContainers(): void
    {
        $envelope = Event::of('checkout.blocks.fell_back', [
            'duplicate' => false,
            'http_status' => 503,
            'payload' => ['not' => 'scalar'],
        ])->envelope(0);

        $this->assertFalse($envelope['attrs']['duplicate']);
        $this->assertSame(503, $envelope['attrs']['http_status']);
        $this->assertArrayNotHasKey('payload', $envelope['attrs']);
    }

    public function testEnvelopeUsesRfc3339AndOmitsEmptySections(): void
    {
        $envelope = Event::of('session.started')->envelope(1787250271);

        $this->assertSame('2026-08-20T18:24:31Z', $envelope['occurred_at']);
        $this->assertArrayNotHasKey('attrs', $envelope);
        $this->assertArrayNotHasKey('error', $envelope);
    }

    public function testCorrelationIdsAreTopLevelAndLimitedToThree(): void
    {
        $envelope = Event::of('checkout.hosted.redirected')
            ->about([
                'order_ref' => '000000123',
                'payment_id' => 'pay_1',
                'payment_intent_id' => 'pi_1',
                'customer_email' => 'jane@example.com',
            ])
            ->envelope(0);

        $this->assertSame('000000123', $envelope['order_ref']);
        $this->assertSame('pay_1', $envelope['payment_id']);
        $this->assertSame('pi_1', $envelope['payment_intent_id']);
        $this->assertArrayNotHasKey('customer_email', $envelope);
    }

    public function testStackCarriesFileAndLineOnly(): void
    {
        $envelope = Event::failure('api.request_failed', 'transport', [], new \RuntimeException('boom'))
            ->envelope(0);

        $this->assertSame('RuntimeException', $envelope['error']['type']);

        foreach ($envelope['error']['stack'] as $frame) {
            $this->assertMatchesRegularExpression('/:\d+$/', $frame);
        }
    }

    public function testModulesAreChunkedAndEachAppearsExactlyOnce(): void
    {
        $modules = [];

        for ($i = 0; $i < 70; $i++) {
            $modules['Vendor_Module' . $i] = '1.0.' . $i;
        }

        $seen = [];

        foreach (Event::environmentPlugins($modules) as $event) {
            $fields = $event->fields();

            $this->assertSame(70, $fields['plugin_count']);
            $this->assertLessThanOrEqual(Event::MAX_ATTRS, count($fields));

            unset($fields['plugin_count'], $fields['chunk']);

            foreach ($fields as $name => $version) {
                $this->assertArrayNotHasKey($name, $seen);
                $seen[$name] = $version;
            }
        }

        $this->assertCount(70, $seen);
    }
}
