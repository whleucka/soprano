<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\WrappedService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for Wrapped: streaks, time-of-day personality, season
 * windows. The SQL side runs against real play history on the host.
 */
class WrappedServiceTest extends TestCase
{
    public function testEveryHourBelongsToExactlyOneDaypart(): void
    {
        $hours = array_merge(...array_column(WrappedService::DAYPARTS, 'hours'));
        sort($hours);

        $this->assertSame(range(0, 23), $hours);
    }

    public function testLongestStreakFindsTheLongestConsecutiveRun(): void
    {
        $streak = WrappedService::longestStreak([
            '2026-08-01', '2026-08-02',
            '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13',
            '2026-08-20',
        ]);

        $this->assertSame(['days' => 4, 'from' => 'Aug 10', 'to' => 'Aug 13'], $streak);
    }

    public function testLongestStreakIgnoresOrderAndDuplicates(): void
    {
        $streak = WrappedService::longestStreak(['2026-09-03', '2026-09-01', '2026-09-02', '2026-09-02']);

        $this->assertSame(3, $streak['days']);
        $this->assertSame('Sep 1', $streak['from']);
    }

    public function testLongestStreakCrossesMonthBoundaries(): void
    {
        $streak = WrappedService::longestStreak(['2026-07-30', '2026-07-31', '2026-08-01']);

        $this->assertSame(['days' => 3, 'from' => 'Jul 30', 'to' => 'Aug 1'], $streak);
    }

    public function testLongestStreakPrefersTheMostRecentOfEqualRuns(): void
    {
        $streak = WrappedService::longestStreak(['2026-08-01', '2026-08-02', '2026-09-05', '2026-09-06']);

        $this->assertSame('Sep 5', $streak['from']);
    }

    public function testNoStreakBelowTwoDays(): void
    {
        $this->assertNull(WrappedService::longestStreak([]));
        $this->assertNull(WrappedService::longestStreak(['2026-08-01', '2026-08-03']));
    }

    public function testDaypartPicksTheDominantPartAndWrapsNightPastMidnight(): void
    {
        $byHour = array_fill(0, 24, 0);
        $byHour[23] = 30;
        $byHour[1]  = 40;   // night spans midnight: 23 + 1 belong together
        $byHour[14] = 50;   // the single busiest hour is still daytime

        $part = WrappedService::daypart($byHour);

        $this->assertSame('night', $part['key']);
        $this->assertSame(58, $part['share']);   // 70 / 120
        $this->assertSame('2 pm', $part['peak_hour']);
    }

    public function testDaypartIsNullWithoutPlays(): void
    {
        $this->assertNull(WrappedService::daypart(array_fill(0, 24, 0)));
    }

    public function testHourLabel(): void
    {
        $this->assertSame('12 am', WrappedService::hourLabel(0));
        $this->assertSame('9 am', WrappedService::hourLabel(9));
        $this->assertSame('12 pm', WrappedService::hourLabel(12));
        $this->assertSame('11 pm', WrappedService::hourLabel(23));
    }

    public function testSeasonWindow(): void
    {
        $svc = new \ReflectionClass(WrappedService::class);
        $wrapped = $svc->newInstanceWithoutConstructor();

        $this->assertFalse($wrapped->isSeason(new \DateTimeImmutable('2026-12-14')));
        $this->assertTrue($wrapped->isSeason(new \DateTimeImmutable('2026-12-15')));
        $this->assertTrue($wrapped->isSeason(new \DateTimeImmutable('2027-01-15')));
        $this->assertFalse($wrapped->isSeason(new \DateTimeImmutable('2027-01-16')));

        $this->assertSame(2026, $wrapped->seasonYear(new \DateTimeImmutable('2026-11-23')));
        $this->assertSame(2026, $wrapped->seasonYear(new \DateTimeImmutable('2027-01-10')));
    }
}
