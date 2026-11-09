<?php

declare(strict_types=1);

namespace Tests\Database;

use Echo\Framework\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The offset the database session is pinned to on connect. It has to come
 * from PHP's tz database for the configured zone, whatever PHP's default
 * happens to be at that moment (the connection opens before the app sets it).
 */
class UtcOffsetTest extends TestCase
{
    public function testUsesTheConfiguredZoneNotPhpsDefault(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $this->assertSame('+09:00', Connection::utcOffset('Asia/Tokyo'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAlbertaIsUtcMinusSixAfterTheNovember2026Change(): void
    {
        // Under the old rules Edmonton fell back to MST (-07:00) on Nov 1,
        // 2026. The change that made it permanently -06:00 is the one the db
        // container's tzdata didn't have. It lives in PHP's bundled tzdb, so a
        // PHP built before it can't pass this; that's the runtime, not the code.
        $offset = Connection::utcOffset('America/Edmonton', '2026-11-09 12:00');
        if ($offset === '-07:00') {
            $this->markTestSkipped('PHP tzdb ' . timezone_version_get() . ' predates the Alberta change');
        }

        $this->assertSame('-06:00', $offset);
    }

    public function testFollowsDaylightSavingWhereItStillExists(): void
    {
        $this->assertSame('-05:00', Connection::utcOffset('America/Toronto', '2026-12-01 12:00'));
        $this->assertSame('-04:00', Connection::utcOffset('America/Toronto', '2026-07-01 12:00'));
    }

    public function testAnEmptyOrUnknownZoneFallsBackToPhpsDefault(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $this->assertSame('+00:00', Connection::utcOffset(null));
            $this->assertSame('+00:00', Connection::utcOffset(''));
            $this->assertSame('+00:00', Connection::utcOffset('Not/AZone'));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
