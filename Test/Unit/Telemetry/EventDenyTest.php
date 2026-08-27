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
            'a non-Luhn 16-digit run' => [['attrs' => ['note' => 'transaction 1234567890123456 not found']]],
            'a millisecond timestamp' => [['attrs' => ['note' => 'expired at 1787250271000']]],
            'a minor-unit amount' => [['attrs' => ['note' => 'amount 4250 refused']]],
            'an ordinary order reference' => [['attrs' => ['order_ref' => '000000123']]],
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
}
