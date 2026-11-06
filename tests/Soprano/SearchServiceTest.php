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

    /** The cheap path must not touch the database at all. */
    public function testGetSearchStateDoesNotRefreshLikes(): void
    {
        $music = $this->createMock(MusicService::class);
        $music->expects($this->never())->method('refreshLikedState');
        $search = new SearchService($music);

        state()->search = ['term' => 'x', 'tracks' => [['hash' => 'a', 'liked' => 1]], 'context' => null];

        $this->assertSame([['hash' => 'a', 'liked' => 1]], $search->getSearchState()['tracks']);
    }

    /** The results pane still gets current hearts. */
    public function testGetSearchRefreshesLikes(): void
    {
        $music = $this->createMock(MusicService::class);
        $music->expects($this->once())->method('refreshLikedState')
            ->willReturn([['hash' => 'a', 'liked' => 0]]);
        $search = new SearchService($music);

        state()->search = ['term' => 'x', 'tracks' => [['hash' => 'a', 'liked' => 1]], 'context' => null];

        $this->assertSame(0, $search->getSearch()['tracks'][0]['liked']);
    }
}
