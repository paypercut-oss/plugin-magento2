<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Telemetry\Event;
use PHPUnit\Framework\TestCase;

/**
 * The privacy boundary. Every case here is a thing that must never reach the wire.
 */
class EventDenyTest extends TestCase
{
    const SECRET = 'ppc_sk_live_9f2b7c4d1e6a8035bd47ce91';

    /**
     * @dataProvider deniedFields
     * @param array $fields
     */
    public function testDeniedFieldsAreRefused(array $fields): void
    {
        $this->assertTrue(Event::isDenied($fields, ['ppc_live_store_secret']));
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function deniedFields(): array
    {
        return [
            'secret in the key name' => [['attrs' => ['api_client_secret' => 'anything']]],
            'token in the key name' => [['attrs' => ['telemetry_token' => 'x']]],
            'password in the key name' => [['attrs' => ['password' => 'x']]],
            'nonce in the key name' => [['attrs' => ['nonce' => 'x']]],
            'authorization in the key name' => [['attrs' => ['authorization' => 'x']]],
            'a key ending in _key' => [['attrs' => ['api_key' => 'x']]],
            'a paypercut key mid-string' => [['attrs' => ['note' => 'rejected ppc_live_store_secret']]],
            'a jwt mid-string' => [['attrs' => ['note' => 'bearer eyJhbGciOiJSUzI1NiJ9.body']]],
            'a whsec prefix' => [['attrs' => ['note' => 'got whsec_abc']]],
            'a card number in prose' => [['attrs' => ['note' => 'Card 4111111111111111 was declined']]],
            'a spaced card number' => [['attrs' => ['note' => 'card 4111 1111 1111 1111 declined']]],
            'the store secret verbatim' => [['attrs' => ['note' => 'upstream said ppc_live_store_secret is bad']]],
            'a denied key nested in error' => [['error' => ['code' => 'x', 'api_key' => 'y']]],
            'a PAN in KEY position' => [['attrs' => ['4111111111111111' => 'x']]],
            'a PAN in prose in KEY position' => [['attrs' => ['card 4111 1111 1111 1111 declined' => 'x']]],
            'a paypercut key in KEY position' => [['attrs' => ['ppc_live_store_secret' => 'x']]],
            'a jwt in KEY position' => [['attrs' => ['bearer eyJhbGciOiJSUzI1NiJ9.body' => 'x']]],
            'a store secret in KEY position two levels down' => [
                ['error' => ['code' => 'x', 'stack' => ['ppc_live_store_secret' => 'Model/Api/Client.php:1']]],
            ],
            'a secret two levels down in error.stack' => [
                ['error' => ['code' => 'x', 'stack' => ['module/File.php:1 ppc_live_store_secret']]],
            ],
        ];
    }

    /**
     * @dataProvider permittedFields
     * @param array $fields
     */
    public function testPermittedFieldsSurvive(array $fields): void
    {
        $this->assertFalse(Event::isDenied($fields, ['ppc_live_store_secret']));
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function permittedFields(): array
    {
        return [
            'disk_usage is not a secret key' => [['attrs' => ['note' => 'disk_usage exceeded']]],
            'backpack_pk_none is not a key' => [['attrs' => ['note' => 'backpack_pk_none missing']]],
            'risk_free is not a secret key' => [['attrs' => ['note' => 'risk_free window elapsed']]],
            'a digit run under the card floor' => [['attrs' => ['note' => 'transaction 123456789012 not found']]],
            'a millisecond timestamp' => [['attrs' => ['note' => 'expired at 1787250271000']]],
            'a minor-unit amount' => [['attrs' => ['note' => 'amount 4250 refused']]],
            'an ordinary order reference' => [['attrs' => ['order_ref' => '000000123']]],
            'module names in KEY position' => [
                ['attrs' => ['Magento_Sales' => '103.0.7', 'Mageplaza_Core' => '1.5.5', 'PayPal_Braintree' => '4.6.0']],
            ],
            'the attribute names this module sends' => [
                ['attrs' => ['order_status' => 'processing', 'has_refund_id' => true, 'duration_ms' => 1387]],
            ],
            'a stack of relative paths' => [
                ['error' => ['code' => 'http_500', 'stack' => ['Model/Api/Client.php:214']]],
            ],
        ];
    }

    public function testAnEmptySecretDoesNotMatchEveryString(): void
    {
        $this->assertFalse(Event::isDenied(['attrs' => ['note' => 'all fine']], ['', null]));
    }

    /**
     * The bound on recursion is a denial, not a pass: a structure the screen
     * cannot finish walking is one it cannot vouch for.
     */
    public function testNestingBeyondTheScreenDepthIsDenied(): void
    {
        $deep = 'x';

        for ($i = 0; $i <= Event::MAX_SCREEN_DEPTH; $i++) {
            $deep = ['level' => $deep];
        }

        $this->assertTrue(Event::isDenied(['attrs' => $deep]));
    }

    /**
     * A value the screen cannot render for comparison is denied, not skipped.
     */
    public function testAnUnrenderableValueIsDenied(): void
    {
        $this->assertTrue(Event::isDenied(['attrs' => ['note' => new \stdClass()]]));
    }

    /**
     * json_encode puts an int on the wire verbatim, so the screen must read it
     * as the digits it will become.
     *
     * @dataProvider nonStringPoisons
     * @param mixed $value
     */
    public function testNonStringScalarsAreScreened($value): void
    {
        $this->assertTrue(Event::isDenied(['attrs' => ['ref' => $value]]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonStringPoisons(): array
    {
        return [
            'an integer PAN' => [4111111111111111],
            'a float PAN' => [4111111111111111.0],
        ];
    }

    /**
     * The ordinary numeric attributes this module actually sends stay.
     */
    public function testOrdinaryNumbersSurvive(): void
    {
        $this->assertFalse(Event::isDenied([
            'attrs' => [
                'expires_at' => 1787250271,
                'http_status' => 401,
                'plugin_count' => 137,
                'is_ssl' => true,
                'level' => 1,
            ],
        ]));
    }

    public function testLuhnScreenIgnoresRunsOutsideCardLength(): void
    {
        $this->assertFalse(Event::containsCardNumber('123456789012'));
        $this->assertTrue(Event::containsCardNumber('4111111111111111'));
    }

    /**
     * A PAN with digits stuck to it is still a PAN. Testing only the maximal
     * digit run let one adjacent digit carry the whole number through.
     *
     * @dataProvider buriedCardNumbers
     * @param string $value
     */
    public function testACardNumberInsideALongerDigitRunIsFound(string $value): void
    {
        $this->assertTrue(Event::containsCardNumber($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function buriedCardNumbers(): array
    {
        return [
            'one digit in front' => ['94111111111111111'],
            'one digit behind' => ['41111111111111119'],
            'buried in a long run' => [str_repeat('7', 40) . '4111111111111111' . str_repeat('7', 40)],
            'behind an order-shaped prefix' => ['999999999999995555555555554444'],
            'inside a separated run' => ['99-4111-1111-1111-1111-99'],
        ];
    }

    /**
     * Every network this estate sees is still caught, at every length it issues.
     *
     * @dataProvider realCardNumbers
     * @param string $pan
     */
    public function testEveryIssuedCardNumberIsCaught(string $pan): void
    {
        $this->assertTrue(Event::containsCardNumber($pan));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realCardNumbers(): array
    {
        return [
            'visa 13' => ['4222222222222'],
            'visa 16' => ['4111111111111111'],
            'visa 19' => ['4035501000000008'],
            'mastercard' => ['5555555555554444'],
            'mastercard 2-series' => ['2223003122003222'],
            'amex' => ['378282246310005'],
            'discover' => ['6011111111111117'],
            'diners 14' => ['30569309025904'],
            'diners 38' => ['38520000023237'],
            'jcb' => ['3530111333300000'],
            'unionpay' => ['6212345678901232'],
            'maestro 16' => ['6759649826438453'],
            'maestro 19' => ['6304000000000000247'],
        ];
    }

    /**
     * A grouped PAN is a PAN whichever character separates the groups. Only
     * space and hyphen were recognised, so a value pasted out of a spreadsheet
     * or a log walked straight through.
     *
     * @dataProvider separatedCardNumbers
     * @param string $value
     */
    public function testGroupSeparatorsDoNotHideACardNumber(string $value): void
    {
        $this->assertTrue(Event::containsCardNumber($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function separatedCardNumbers(): array
    {
        return [
            'space' => ['4111 1111 1111 1111'],
            'hyphen' => ['4111-1111-1111-1111'],
            'dot' => ['4111.1111.1111.1111'],
            'slash' => ['4111/1111/1111/1111'],
            'underscore' => ['4111_1111_1111_1111'],
            'comma' => ['4111,1111,1111,1111'],
            'tab' => ["4111\t1111\t1111\t1111"],
            'mixed' => ['4111-1111.1111 1111'],
        ];
    }

    /**
     * The scan is gated on an assigned issuer prefix before Luhn runs.
     *
     * Without that gate a 16-digit run holds ten candidate windows, each ~10%
     * likely to pass Luhn by chance, and 60-65% of long numeric order
     * references were denied. Telemetry a merchant cannot use is as bad an
     * outcome as one that leaks.
     */
    public function testUnassignedIssuerPrefixesAreNotCardNumbers(): void
    {
        $this->assertFalse(Event::containsCardNumber('1234567890123456'));
        $this->assertFalse(Event::containsCardNumber('1787250271000'));
        $this->assertFalse(Event::containsCardNumber('9999999999999999'));
    }

    /**
     * The measured false-positive rate on merchant order numbers, pinned.
     *
     * Deterministic seed so this is a regression bound, not a flake: before the
     * issuer gate this stood at 304/500.
     */
    public function testRandomSixteenDigitReferencesMostlySurvive(): void
    {
        mt_srand(20260827);
        $denied = 0;

        for ($i = 0; $i < 500; $i++) {
            $reference = '';

            for ($digit = 0; $digit < 16; $digit++) {
                $reference .= (string) mt_rand(0, 9);
            }

            if (Event::containsCardNumber($reference)) {
                $denied++;
            }
        }

        $this->assertLessThan(75, $denied, 'false positives on 16-digit references: ' . $denied . '/500');
    }

    /**
     * A credential is quoted mid-string by upstream errors and cut at either
     * end by the byte clamp, so the comparison cannot anchor to either end.
     *
     * @dataProvider secretFragments
     * @param string $note
     */
    public function testAFragmentOfTheStoreSecretIsDenied(string $note): void
    {
        $this->assertTrue(Event::isDenied(['attrs' => ['note' => $note]], [self::SECRET]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secretFragments(): array
    {
        return [
            'verbatim' => ['upstream rejected ' . self::SECRET],
            'head only' => ['upstream rejected ' . substr(self::SECRET, 0, 20)],
            'tail only' => ['upstream rejected ' . substr(self::SECRET, -20)],
            'middle slice' => ['upstream rejected ' . substr(self::SECRET, 8, 16)],
            'middle slice, mid-string' => ['saw ' . substr(self::SECRET, 10, 14) . ' in the response'],
        ];
    }

    /**
     * The whole realistic module inventory reaches the wire.
     *
     * `auth` and `nonce` as bare substrings dropped real slugs, and a denied
     * key bins the event its 13 chunk-mates were travelling in.
     */
    public function testARealisticModuleInventorySurvives(): void
    {
        $inventory = [
            'ParadoxLabs_Authnetcim' => '4.6.1',
            'Authorizenet_Acceptjs' => '1.0.0',
            'Amasty_Nonces' => '1.2.0',
            'MSP_TwoFactorAuth' => '1.4.2',
            'Klarna_Ordermanagement' => '9.2.0',
            'PayPal_Braintree' => '4.6.0',
            'Smile_ElasticsuiteCore' => '2.11.2',
            'Dotdigitalgroup_Email' => '4.24.0',
        ];

        $shipped = [];

        foreach (Event::environmentPlugins($inventory) as $event) {
            $envelope = $event->envelope(0);

            $this->assertFalse(Event::envelopeDenied($envelope, [self::SECRET]));

            foreach (array_keys($envelope['attrs']) as $key) {
                $shipped[$key] = true;
            }
        }

        foreach (array_keys($inventory) as $slug) {
            $this->assertArrayHasKey($slug, $shipped, $slug . ' was dropped from the inventory');
        }
    }

    /**
     * A correlation id joins a client event to a server log. Real references
     * must survive byte for byte; a path or a URL is not a reference.
     *
     * @dataProvider correlationIds
     * @param string $value
     * @param string $expected
     */
    public function testCorrelationIdsAreLosslessButShaped(string $value, string $expected): void
    {
        $this->assertSame($expected, Event::correlationId($value));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function correlationIds(): array
    {
        return [
            'a magento increment id' => ['000000123', '000000123'],
            'a store-prefixed increment id' => ['2000000045', '2000000045'],
            'a merchant-shaped reference' => ['MAG-2026/8891', 'MAG-2026/8891'],
            'a paypercut payment intent' => ['pi_3Ab4Cd5Ef6Gh7Ij', 'pi_3Ab4Cd5Ef6Gh7Ij'],
            'a dotted reference' => ['store.1.order.8891', 'store.1.order.8891'],
            'a hash reference' => ['ORDER#8891', 'ORDER#8891'],
            'path traversal' => ['../../etc/passwd', ''],
            'a leading dot segment' => ['./relative', ''],
            'a protocol-relative url' => ['//evil.example/x', ''],
            'a trailing separator' => ['8891/', ''],
            'doubled separators' => ['a//b', ''],
        ];
    }
}
