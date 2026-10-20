<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\RadioService;
use PHPUnit\Framework\TestCase;

/**
 * groupStations() is what the radio index renders its headings from. It takes
 * rows already in getStations() order, so these cover the grouping alone.
 */
class RadioServiceTest extends TestCase
{
    private function station(string $name, ?string $country, bool $liked = false): array
    {
        return ['name' => $name, 'country' => $country, 'liked' => $liked];
    }

    public function testGroupsByCountryInTheOrderGiven(): void
    {
        $groups = (new RadioService())->groupStations([
            $this->station('ABC Jazz', 'Australia'),
            $this->station('triple j', 'Australia'),
            $this->station('CBC Radio One', 'Canada'),
        ]);

        $this->assertSame(['Australia', 'Canada'], array_column($groups, 'name'));
        $this->assertSame(['ABC Jazz', 'triple j'], array_column($groups[0]['stations'], 'name'));
        $this->assertFalse($groups[0]['liked']);
    }

    /** A liked station leads in its own group and still appears under its country. */
    public function testLikedStationsGetTheirOwnGroupFirst(): void
    {
        $groups = (new RadioService())->groupStations([
            $this->station('ABC Jazz', 'Australia'),
            $this->station('CBC Radio One', 'Canada', liked: true),
        ]);

        $this->assertSame(['Liked', 'Australia', 'Canada'], array_column($groups, 'name'));
        $this->assertTrue($groups[0]['liked']);
        $this->assertSame(['CBC Radio One'], array_column($groups[0]['stations'], 'name'));
        $this->assertSame(['CBC Radio One'], array_column($groups[2]['stations'], 'name'));
    }

    public function testStationsWithoutACountryGoUnderElsewhere(): void
    {
        $groups = (new RadioService())->groupStations([
            $this->station('Yacht Rock Radio', null),
        ]);

        $this->assertSame(['Elsewhere'], array_column($groups, 'name'));
    }

    public function testNoStationsMeansNoGroups(): void
    {
        $this->assertSame([], (new RadioService())->groupStations([]));
    }
}
