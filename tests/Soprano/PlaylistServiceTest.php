<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\PlaylistService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for queue advancement: shuffle order walking and repeat modes.
 */
class PlaylistServiceTest extends TestCase
{
    private PlaylistService $playlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->playlist = new PlaylistService();
        // State lives in a per-process singleton — reset the queue so tests
        // don't leak shuffle/repeat modes into each other.
        state()->playlist = [
            "tracks" => [],
            "index" => 0,
            "shuffle" => false,
            "order" => null,
            "repeat" => "off",
        ];
    }

    private function tracks(int $count): array
    {
        return array_map(fn($i) => ["hash" => "t$i", "title" => "Track $i"], range(0, $count - 1));
    }

    public function testSequentialNextAdvancesInOrder(): void
    {
        $this->playlist->setPlaylist($this->tracks(3));

        $this->assertSame("t1", $this->playlist->changePlaylistTrack(true)["hash"]);
        $this->assertSame("t2", $this->playlist->changePlaylistTrack(true)["hash"]);
    }

    public function testManualNextWrapsAtEndRegardlessOfRepeat(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 2);

        $this->assertSame("t0", $this->playlist->changePlaylistTrack(true)["hash"]);
    }

    public function testManualPrevWrapsToEnd(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 0);

        $this->assertSame("t2", $this->playlist->changePlaylistTrack(false)["hash"]);
    }

    public function testAutoAdvanceStopsAtQueueEndWhenRepeatOff(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 2);

        $this->assertFalse($this->playlist->changePlaylistTrack(true, auto: true));
    }

    public function testAutoAdvanceWrapsWhenRepeatAll(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 2);
        $this->playlist->cycleRepeat(); // all

        $next = $this->playlist->changePlaylistTrack(true, auto: true);
        $this->assertSame("t0", $next["hash"]);
    }

    public function testAutoAdvanceReplaysCurrentWhenRepeatOne(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 1);
        $this->playlist->cycleRepeat(); // all
        $this->playlist->cycleRepeat(); // one

        $next = $this->playlist->changePlaylistTrack(true, auto: true);
        $this->assertSame("t1", $next["hash"]);
        // Index must not move — the same track plays again.
        $this->assertSame(1, $this->playlist->getPlaylist()["index"]);
    }

    public function testCycleRepeatCyclesThroughModes(): void
    {
        $this->assertSame("all", $this->playlist->cycleRepeat());
        $this->assertSame("one", $this->playlist->cycleRepeat());
        $this->assertSame("off", $this->playlist->cycleRepeat());
    }

    public function testToggleShuffleBuildsOrderAnchoredOnCurrentTrack(): void
    {
        $this->playlist->setPlaylist($this->tracks(10), 4);
        $this->playlist->toggleShuffle();

        $state = $this->playlist->getPlaylist();
        $this->assertTrue($state["shuffle"]);
        $order = $state["order"];
        $this->assertIsArray($order);
        // A permutation of every track index, current track leading.
        $sorted = $order;
        sort($sorted);
        $this->assertSame(range(0, 9), $sorted);
        $this->assertSame(4, $order[0]);
    }

    public function testToggleShuffleOffClearsOrder(): void
    {
        $this->playlist->setPlaylist($this->tracks(5));
        $this->playlist->toggleShuffle();
        $this->playlist->toggleShuffle();

        $state = $this->playlist->getPlaylist();
        $this->assertFalse($state["shuffle"]);
        $this->assertNull($state["order"]);
    }

    public function testShuffleVisitsEveryTrackOnceBeforeRepeating(): void
    {
        $this->playlist->setPlaylist($this->tracks(8), 0);
        $this->playlist->toggleShuffle();

        $seen = ["t0"];
        for ($i = 0; $i < 7; $i++) {
            $seen[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        $this->assertCount(8, array_unique($seen));
    }

    public function testShufflePrevWalksBackThroughHistory(): void
    {
        $this->playlist->setPlaylist($this->tracks(8), 0);
        $this->playlist->toggleShuffle();

        $first = $this->playlist->changePlaylistTrack(true)["hash"];
        $second = $this->playlist->changePlaylistTrack(true)["hash"];
        $this->assertNotSame($first, $second);

        $this->assertSame($first, $this->playlist->changePlaylistTrack(false)["hash"]);
        $this->assertSame("t0", $this->playlist->changePlaylistTrack(false)["hash"]);
    }

    public function testShuffleAutoAdvanceStopsAtEndOfOrderWhenRepeatOff(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 0);
        $this->playlist->toggleShuffle();

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotFalse($this->playlist->changePlaylistTrack(true, auto: true));
        }
        $this->assertFalse($this->playlist->changePlaylistTrack(true, auto: true));
    }

    public function testShuffleAutoAdvanceWrapsWhenRepeatAll(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 0);
        $this->playlist->toggleShuffle();
        $this->playlist->cycleRepeat(); // all

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $next = $this->playlist->changePlaylistTrack(true, auto: true);
            $this->assertNotFalse($next);
            $seen[] = $next["hash"];
        }
        // Four auto-advances over four tracks lands back on the order's start.
        $this->assertSame("t0", $seen[3]);
    }

    public function testShuffleOrderRebuiltWhenQueueChanges(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 0);
        $this->playlist->toggleShuffle();
        // New queue resets the order; the next advance deals a fresh one.
        $this->playlist->setPlaylist($this->tracks(6), 2);

        $this->assertNotFalse($this->playlist->changePlaylistTrack(true));
        $order = $this->playlist->getPlaylist()["order"];
        $sorted = $order;
        sort($sorted);
        $this->assertSame(range(0, 5), $sorted);
        $this->assertSame(2, $order[0]);
    }

    public function testSingleTrackAutoRepeatAllReplays(): void
    {
        $this->playlist->setPlaylist($this->tracks(1));
        $this->playlist->cycleRepeat(); // all

        $next = $this->playlist->changePlaylistTrack(true, auto: true);
        $this->assertSame("t0", $next["hash"]);
    }

    public function testSingleTrackManualNextIsNoop(): void
    {
        $this->playlist->setPlaylist($this->tracks(1));

        $this->assertFalse($this->playlist->changePlaylistTrack(true));
    }

    public function testEmptyQueueReturnsFalse(): void
    {
        $this->playlist->clearPlaylist();

        $this->assertFalse($this->playlist->changePlaylistTrack(true));
        $this->assertFalse($this->playlist->changePlaylistTrack(true, auto: true));
    }
}
