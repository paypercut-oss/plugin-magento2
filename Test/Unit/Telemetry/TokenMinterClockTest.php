<?php
declare(strict_types=1);

namespace Paypercut\Payment\Test\Unit\Telemetry;

use Paypercut\Payment\Model\Telemetry\TokenMinter;
use PHPUnit\Framework\TestCase;

/**
 * Token lifetime is measured on the mint's clock, never copied onto this one.
 */
class TokenMinterClockTest extends TestCase
{
    public function testLifetimeIsADurationOnTheMintsClock(): void
    {
        $lifetime = TokenMinter::deriveLifetime(
            '2026-08-18T22:04:31Z',
            'Tue, 18 Aug 2026 21:04:31 GMT',
            // This server thinks it is two hours earlier; the duration must not care.
            (int) strtotime('2026-08-18T19:04:31Z')
        );

        $this->assertSame(3600, $lifetime);
    }

    public function testAMissingDateHeaderFallsBackToTheLocalClock(): void
    {
        $now = (int) strtotime('2026-08-18T21:04:31Z');

        $this->assertSame(3600, TokenMinter::deriveLifetime('2026-08-18T22:04:31Z', '', $now));
    }

    public function testAnUnparsableExpiryYieldsNoLifetime(): void
    {
        $this->assertSame(0, TokenMinter::deriveLifetime('not-a-date', '', time()));
    }

    public function testSkewIsSignedAndZeroWithoutADateHeader(): void
    {
        $now = (int) strtotime('2026-08-18T21:04:31Z');

        $this->assertSame(60, TokenMinter::skew('Tue, 18 Aug 2026 21:05:31 GMT', $now));
        $this->assertSame(-60, TokenMinter::skew('Tue, 18 Aug 2026 21:03:31 GMT', $now));
        $this->assertSame(0, TokenMinter::skew('', $now));
    }
}
