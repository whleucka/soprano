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
    public function testEveryStationWhereTokenHasAKnownPercentile(): void
    {
        // Tokens the thresholds() query produces — keep in sync with it.
        $known = ['{d40}', '{d60}', '{d75}', '{l25}', '{l40}', '{l50}', '{l75}',
                  '{e50}', '{z60}', '{dc40}'];

        foreach (StationService::STATIONS as $slug => $station) {
            preg_match_all('/\{[a-z]+\d{2}\}/', $station['where'], $m);
            foreach ($m[0] as $token) {
                $this->assertContains($token, $known, "$slug uses unresolved token $token");
            }
        }
    }

    public function testResolveWhereSubstitutesAllTokens(): void
    {
        $thresholds = [
            '{d40}' => '1.1040', '{d60}' => '1.1708', '{d75}' => '1.2633',
            '{l25}' => '-18.4500', '{l40}' => '-16.9860', '{l50}' => '-16.1150',
            '{l75}' => '-14.1325', '{e50}' => '0.0482',
            '{z60}' => '0.0716', '{dc40}' => '3.0410',
        ];

        foreach (StationService::STATIONS as $slug => $station) {
            $where = StationService::resolveWhere($station['where'], $thresholds);
            $this->assertStringNotContainsString('{', $where, "$slug has an unresolved token");
        }
    }

    public function testSuggestionWindows(): void
    {
        $this->assertSame('feel-good', StationService::suggestedFor(8));
        $this->assertSame('party', StationService::suggestedFor(18));
        $this->assertSame('chill', StationService::suggestedFor(21));
        // Wrap-around window: 23:00 through 05:00 the next morning.
        $this->assertSame('wind-down', StationService::suggestedFor(23));
        $this->assertSame('wind-down', StationService::suggestedFor(2));
        $this->assertSame('wind-down', StationService::suggestedFor(5));
        // Midday gap — no pick, nothing badged.
        $this->assertNull(StationService::suggestedFor(14));
    }

    public function testWindowBoundsAreExclusiveAtEnd(): void
    {
        // feel-good runs [6, 12): 12:00 falls outside.
        $this->assertSame('feel-good', StationService::suggestedFor(6));
        $this->assertNull(StationService::suggestedFor(12));
    }
}
