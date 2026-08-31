<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\StationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for stations: time-of-day suggestion windows and
 * percentile-token resolution. The SQL side (thresholds, pools, deals)
 * is exercised against the dev database via `soprano:stations`.
 */
class StationServiceTest extends TestCase
{
    /** Tokens the thresholds() query produces — keep in sync with it. */
    private const KNOWN_TOKENS = [
        '{d40}', '{d60}', '{d75}',
        '{l25}', '{l40}', '{l50}', '{l75}',
        '{e50}', '{z60}', '{dc40}', '{dc75}',
    ];

    public function testEveryStationWhereTokenHasAKnownPercentile(): void
    {
        foreach (StationService::STATIONS as $slug => $station) {
            preg_match_all('/\{[a-z]+\d{2}\}/', $station['where'], $m);
            foreach ($m[0] as $token) {
                $this->assertContains($token, self::KNOWN_TOKENS, "$slug uses unresolved token $token");
            }
        }
    }

    public function testResolveWhereSubstitutesAllTokens(): void
    {
        $thresholds = [
            '{d40}' => '1.1040', '{d60}' => '1.1708', '{d75}' => '1.2633',
            '{l25}' => '-18.4500', '{l40}' => '-16.9860', '{l50}' => '-16.1150',
            '{l75}' => '-14.1325', '{e50}' => '0.0482',
            '{z60}' => '0.0716', '{dc40}' => '3.0410', '{dc75}' => '5.2870',
        ];

        // The fixture has to cover the full token vocabulary, or a station
        // using a token nobody thought to add here would pass by default.
        $this->assertSame(
            [],
            array_diff(self::KNOWN_TOKENS, array_keys($thresholds)),
            'fixture is missing a known token',
        );

        foreach (StationService::STATIONS as $slug => $station) {
            $where = StationService::resolveWhere($station['where'], $thresholds);
            $this->assertStringNotContainsString('{', $where, "$slug has an unresolved token");
        }
    }

    public function testSuggestionWindows(): void
    {
        $this->assertSame('feel-good', StationService::suggestedFor(8));
        $this->assertSame('steady', StationService::suggestedFor(14));
        $this->assertSame('party', StationService::suggestedFor(18));
        $this->assertSame('chill', StationService::suggestedFor(21));
        // Wrap-around window: 23:00 through 05:00 the next morning.
        $this->assertSame('wind-down', StationService::suggestedFor(23));
        $this->assertSame('wind-down', StationService::suggestedFor(2));
        $this->assertSame('wind-down', StationService::suggestedFor(5));
    }

    public function testWindowBoundsAreExclusiveAtEnd(): void
    {
        // feel-good runs [6, 12): 12:00 belongs to the next window along.
        $this->assertSame('feel-good', StationService::suggestedFor(6));
        $this->assertSame('feel-good', StationService::suggestedFor(11));
        $this->assertSame('steady', StationService::suggestedFor(12));
    }

    /**
     * The rail's lead button is whichever station is badged 'now', so an
     * uncovered hour does not merely drop the badge — it leaves the rail
     * leading with whatever happens to be first in the array literal.
     */
    public function testEveryHourOfTheDayHasASuggestion(): void
    {
        foreach (range(0, 23) as $hour) {
            $this->assertNotNull(
                StationService::suggestedFor($hour),
                sprintf('%02d:00 has no station to badge', $hour),
            );
        }
    }

    /**
     * suggestedFor() is first-match-wins over definition order, so an overlap
     * would silently shadow one station with another. Assert the windows
     * genuinely tile the clock instead of relying on that ordering.
     */
    public function testWindowsTileTheClockExactlyOnce(): void
    {
        $covers = [];
        foreach (StationService::STATIONS as $slug => $station) {
            $window = $station['hours'] ?? null;
            if ($window === null) {
                continue;
            }
            [$start, $end] = $window;
            $this->assertGreaterThanOrEqual(0, $start, "$slug start is not a clock hour");
            $this->assertLessThanOrEqual(23, $start, "$slug start is not a clock hour");
            $this->assertGreaterThanOrEqual(0, $end, "$slug end is not a clock hour");
            $this->assertLessThanOrEqual(23, $end, "$slug end is not a clock hour");
            $this->assertNotSame($start, $end, "$slug window is empty");

            foreach (range(0, 23) as $hour) {
                $in = $start <= $end
                    ? ($hour >= $start && $hour < $end)
                    : ($hour >= $start || $hour < $end);
                if ($in) {
                    $covers[$hour][] = $slug;
                }
            }
        }

        foreach (range(0, 23) as $hour) {
            $this->assertCount(
                1,
                $covers[$hour] ?? [],
                sprintf('%02d:00 is covered by [%s]', $hour, implode(', ', $covers[$hour] ?? [])),
            );
        }
    }

    /**
     * While the features backfill is mid-library a station can have an empty
     * pool; stations() drops those and passes what is left as $eligible. The
     * badge has to survive that rather than vanishing.
     */
    public function testSuggestionFallsBackWhenTheHourPickIsNotEligible(): void
    {
        $all = array_keys(StationService::STATIONS);

        // Full eligibility changes nothing.
        $this->assertSame('steady', StationService::suggestedFor(14, $all));

        // Drop the hour's own pick: falls through to another timed station,
        // never to null.
        $withoutSteady = array_values(array_diff($all, ['steady']));
        $fallback = StationService::suggestedFor(14, $withoutSteady);
        $this->assertNotNull($fallback);
        $this->assertContains($fallback, $withoutSteady);

        // Only untimed stations left: still badges one of them.
        $untimed = [];
        foreach (StationService::STATIONS as $slug => $station) {
            if (!isset($station['hours'])) {
                $untimed[] = $slug;
            }
        }
        $this->assertNotEmpty($untimed, 'expected some untimed stations');
        $this->assertContains(StationService::suggestedFor(14, $untimed), $untimed);

        // Nothing eligible at all — the rail is empty, so no badge.
        $this->assertNull(StationService::suggestedFor(14, []));
    }
}
