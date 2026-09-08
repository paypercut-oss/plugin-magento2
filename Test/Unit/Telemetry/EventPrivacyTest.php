<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Api\PaypercutApiException;
use Paypercut\Payment\Model\Telemetry\ActiveModules;
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

    public function testAnExceptionMessageNeverTravels(): void
    {
        $envelope = Event::failure(
            'refund.failed',
            'credit_memo_failed',
            ['source' => 'credit_memo'],
            new \RuntimeException('SQLSTATE[42000]: SELECT * FROM sales_order, magento_user@db-01.internal')
        )->envelope(0);

        $this->assertArrayNotHasKey('message', $envelope['error']);
        $this->assertSame('RuntimeException', $envelope['error']['type']);
        $this->assertSame('credit_memo_failed', $envelope['error']['code']);
        $this->assertArrayHasKey('origin', $envelope['attrs']);
    }

    public function testAFatalFromAnUncaughtThrowableReportsTheClassOnly(): void
    {
        $envelope = Event::fatal(
            'Uncaught Magento\\Framework\\Exception\\LocalizedException: '
                . 'The most money available to refund is $120.50 in /var/www/html/app/code/X.php:31'
                . "\nStack trace:\n#0 /var/www/html/index.php(1)",
            '/var/www/html/app/code/X.php',
            31,
            1
        )->envelope(0);

        $this->assertArrayNotHasKey('message', $envelope['error']);
        $this->assertSame('LocalizedException', $envelope['error']['type']);
    }

    public function testAnEngineFatalKeepsPhpsOwnWording(): void
    {
        $envelope = Event::fatal('Allowed memory size of 134217728 bytes exhausted', '/var/www/x.php', 3, 1)
            ->envelope(0);

        $this->assertSame('Allowed memory size of 134217728 bytes exhausted', $envelope['error']['message']);
        $this->assertSame('FatalError', $envelope['error']['type']);
    }

    public function testAttributesStayUnderTheCapAfterTheModuleAddsItsOwn(): void
    {
        $attrs = [];

        for ($i = 0; $i < Event::MAX_ATTRS; $i++) {
            $attrs['caller_' . $i] = $i;
        }

        $envelope = Event::failure('api.request_failed', 'transport', $attrs, new \RuntimeException('boom'))
            ->envelope(0);

        $this->assertLessThanOrEqual(Event::MAX_ATTRS, count($envelope['attrs']));
        $this->assertArrayHasKey('origin', $envelope['attrs']);
    }

    /**
     * Paypercut's edge discards an attribute whose value is empty, and it
     * discards the key with it. Most Magento modules carry no setup_version
     * since 2.3, so an empty one meant reporting no module at all.
     */
    public function testAModuleWithoutASetupVersionStillNamesItself(): void
    {
        $this->assertNotSame('', ActiveModules::UNKNOWN_VERSION);

        $envelope = Event::environmentPlugins([
            'Vendor_Unversioned' => ActiveModules::UNKNOWN_VERSION,
            'Vendor_Versioned' => '1.2.3',
        ])[0]->envelope(0);

        $this->assertSame('unknown', $envelope['attrs']['Vendor_Unversioned']);
        $this->assertSame('1.2.3', $envelope['attrs']['Vendor_Versioned']);
    }

    public function testAModuleNamedLikeASecretDoesNotCostTheWholeInventory(): void
    {
        $modules = ['ParadoxLabs_Authnetcim' => '6.6.0', 'MSP_TwoFactorAuth' => '1.2.3'];

        for ($i = 0; $i < 13; $i++) {
            $modules['Vendor_Module' . $i] = '1.0.' . $i;
        }

        $reported = [];

        foreach (Event::environmentPlugins($modules) as $event) {
            $envelope = $event->envelope(0);

            $this->assertFalse(Event::envelopeDenied($envelope, ['ppc_live_store_secret']));

            foreach ($envelope['attrs'] as $key => $value) {
                if ($key !== 'plugin_count' && $key !== 'chunk') {
                    $reported[] = strpos((string) $key, 'module_') === 0 ? (string) $value : $key . ' ' . $value;
                }
            }
        }

        $this->assertCount(15, $reported);
        $this->assertContains('ParadoxLabs_Authnetcim 6.6.0', $reported);
        $this->assertContains('MSP_TwoFactorAuth 1.2.3', $reported);
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
        $this->assertSame('', Event::identifier("dbg_abc\n"));
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
