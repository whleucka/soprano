<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\MusicService;
use App\Services\Soprano\SearchService;
use PHPUnit\Framework\TestCase;

/**
 * Session-backed search state. Reads state()->search directly rather than
 * going through getSearch(), which re-queries liked state from the database.
 */
class SearchServiceTest extends TestCase
{
    private SearchService $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new SearchService($this->createStub(MusicService::class));
        $this->search->clearSearch();
    }

    /**
     * State writes merge over the previous value, so a feed/genre/decade page
     * that didn't clear 'term' kept the last typed search in the search box
     * and in its "No results found for '…'" message.
     */
    public function testSetSearchResultsClearsAPreviousTerm(): void
    {
        state()->search = ['term' => 'beatles', 'tracks' => [['hash' => 'x']], 'context' => null];

        $this->search->setSearchResults([], ['label' => 'Top Played']);

        $this->assertSame('', state()->search['term']);
        $this->assertSame([], state()->search['tracks']);
        $this->assertSame('Top Played', state()->search['context']['label']);
    }
}
