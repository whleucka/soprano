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

    public function testQueueNextInsertsAfterCurrentTrack(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 1);

        $this->playlist->queueTrack(["hash" => "new", "title" => "New"], next: true);

        $hashes = array_column($this->playlist->getPlaylist()["tracks"], "hash");
        $this->assertSame(["t0", "t1", "new", "t2"], $hashes);
        $this->assertSame("new", $this->playlist->changePlaylistTrack(true)["hash"]);
    }

    public function testQueueLastAppendsToEnd(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 1);

        $this->playlist->queueTrack(["hash" => "new", "title" => "New"]);

        $hashes = array_column($this->playlist->getPlaylist()["tracks"], "hash");
        $this->assertSame(["t0", "t1", "t2", "new"], $hashes);
        // Current position is untouched.
        $this->assertSame(1, $this->playlist->getPlaylist()["index"]);
    }

    public function testQueueIntoEmptyQueueStartsNewQueue(): void
    {
        $this->playlist->clearPlaylist();

        $this->playlist->queueTrack(["hash" => "new", "title" => "New"], next: true);

        $state = $this->playlist->getPlaylist();
        $this->assertSame(["new"], array_column($state["tracks"], "hash"));
        $this->assertSame(0, $state["index"]);
    }

    public function testQueueNextUnderShufflePlaysQueuedTrackNext(): void
    {
        $this->playlist->setPlaylist($this->tracks(6), 2);
        $this->playlist->toggleShuffle();

        $this->playlist->queueTrack(["hash" => "new", "title" => "New"], next: true);

        // The queued track comes immediately, then the walk still visits
        // every remaining track exactly once.
        $this->assertSame("new", $this->playlist->changePlaylistTrack(true)["hash"]);
        $seen = ["t2", "new"];
        for ($i = 0; $i < 5; $i++) {
            $seen[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        $this->assertCount(7, array_unique($seen));
    }

    public function testQueueLastUnderShufflePlaysQueuedTrackLast(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 0);
        $this->playlist->toggleShuffle();

        $this->playlist->queueTrack(["hash" => "new", "title" => "New"]);

        $walk = [];
        for ($i = 0; $i < 4; $i++) {
            $walk[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        $this->assertSame("new", end($walk));
    }

    public function testEmptyQueueReturnsFalse(): void
    {
        $this->playlist->clearPlaylist();

        $this->assertFalse($this->playlist->changePlaylistTrack(true));
        $this->assertFalse($this->playlist->changePlaylistTrack(true, auto: true));
    }

    public function testLikedSourceTracksLikedQueue(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), source: "liked");
        $this->assertTrue($this->playlist->isLikedQueue());

        // Loading any other queue drops the liked source.
        $this->playlist->setPlaylist($this->tracks(2));
        $this->assertFalse($this->playlist->isLikedQueue());
    }

    public function testClearPlaylistDropsSource(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), source: "liked");
        $this->playlist->clearPlaylist();

        $this->assertFalse($this->playlist->isLikedQueue());
    }

    public function testHasTrackFindsByHash(): void
    {
        $this->playlist->setPlaylist($this->tracks(3));

        $this->assertTrue($this->playlist->hasTrack("t1"));
        $this->assertFalse($this->playlist->hasTrack("nope"));
    }

    public function testRemoveTrackBeforeCurrentKeepsCurrentTrack(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 2);

        $this->assertTrue($this->playlist->removeTrack("t0"));

        $state = $this->playlist->getPlaylist();
        $this->assertSame(["t1", "t2", "t3"], array_column($state["tracks"], "hash"));
        // Index follows the current track to its new slot.
        $this->assertSame("t2", $state["tracks"][$state["index"]]["hash"]);
    }

    public function testRemoveTrackAfterCurrentLeavesIndexAlone(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 1);

        $this->assertTrue($this->playlist->removeTrack("t3"));

        $state = $this->playlist->getPlaylist();
        $this->assertSame(1, $state["index"]);
        $this->assertSame("t1", $state["tracks"][$state["index"]]["hash"]);
    }

    public function testRemoveMissingTrackReturnsFalse(): void
    {
        $this->playlist->setPlaylist($this->tracks(3));

        $this->assertFalse($this->playlist->removeTrack("nope"));
        $this->assertCount(3, $this->playlist->getPlaylist()["tracks"]);
    }

    public function testRemoveLastRemainingTrackClampsIndex(): void
    {
        $this->playlist->setPlaylist($this->tracks(1));

        $this->assertTrue($this->playlist->removeTrack("t0"));

        $state = $this->playlist->getPlaylist();
        $this->assertSame([], $state["tracks"]);
        $this->assertSame(0, $state["index"]);
    }

    public function testRemoveTrackUnderShuffleKeepsOrderWalkingSameTracks(): void
    {
        $this->playlist->setPlaylist($this->tracks(5), 0);
        $this->playlist->toggleShuffle();

        $this->assertTrue($this->playlist->removeTrack("t3"));

        $state = $this->playlist->getPlaylist();
        $order = $state["order"];
        $sorted = $order;
        sort($sorted);
        // Order is a permutation of the surviving indices…
        $this->assertSame(range(0, 3), $sorted);
        // …and walking it never yields the removed track.
        $walked = [$state["tracks"][$state["index"]]["hash"]];
        for ($i = 0; $i < 3; $i++) {
            $walked[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        $this->assertNotContains("t3", $walked);
        $this->assertCount(4, array_unique($walked));
    }

    public function testSetSourceRestoresLikedAfterQueueTrackReset(): void
    {
        // Liking into an emptied-out liked queue goes through queueTrack's
        // empty path, which resets the source — setSource puts it back.
        $this->playlist->setPlaylist($this->tracks(1), source: "liked");
        $this->playlist->removeTrack("t0");
        $this->playlist->queueTrack(["hash" => "new", "title" => "New"]);
        $this->playlist->setSource("liked");

        $this->assertTrue($this->playlist->isLikedQueue());
    }

    public function testSyncLikedIsANoopWhenQueueIsNotTheLikedPlaylist(): void
    {
        $this->playlist->setPlaylist($this->tracks(3));

        $this->assertFalse($this->playlist->syncLiked($this->tracks(5), true));
        $this->assertCount(3, $this->playlist->getPlaylist()["tracks"]);
    }

    public function testSyncLikedAppendsOnlyTracksNotAlreadyQueued(): void
    {
        $this->playlist->setPlaylist($this->tracks(2), source: "liked");

        // t0/t1 are already in the liked queue; t2/t3 are the new likes.
        $this->assertTrue($this->playlist->syncLiked($this->tracks(4), true));

        $hashes = array_column($this->playlist->getPlaylist()["tracks"], "hash");
        $this->assertSame(["t0", "t1", "t2", "t3"], $hashes);
    }

    public function testSyncLikedAddingNothingNewReturnsFalse(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), source: "liked");

        $this->assertFalse($this->playlist->syncLiked($this->tracks(3), true));
    }

    public function testSyncUnlikedDropsEveryUnlikedTrack(): void
    {
        $this->playlist->setPlaylist($this->tracks(5), 4, source: "liked");

        $this->assertTrue($this->playlist->syncLiked($this->tracks(3), false));

        $state = $this->playlist->getPlaylist();
        $this->assertSame(["t3", "t4"], array_column($state["tracks"], "hash"));
        // Index follows the playing track to its new slot.
        $this->assertSame("t4", $state["tracks"][$state["index"]]["hash"]);
    }

    public function testSyncUnlikedRemovingPlayingTrackLandsOnWhatSlidIn(): void
    {
        $this->playlist->setPlaylist($this->tracks(4), 1, source: "liked");

        $this->assertTrue($this->playlist->syncLiked([["hash" => "t1"]], false));

        $state = $this->playlist->getPlaylist();
        $this->assertSame(["t0", "t2", "t3"], array_column($state["tracks"], "hash"));
        $this->assertSame("t2", $state["tracks"][$state["index"]]["hash"]);
    }

    public function testSyncUnlikedEverythingEmptiesTheQueue(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 2, source: "liked");

        $this->assertTrue($this->playlist->syncLiked($this->tracks(3), false));

        $state = $this->playlist->getPlaylist();
        $this->assertSame([], $state["tracks"]);
        $this->assertSame(0, $state["index"]);
    }

    public function testQueueTracksUnderShuffleExtendsTheWalk(): void
    {
        $this->playlist->setPlaylist($this->tracks(3), 0, source: "liked");
        $this->playlist->toggleShuffle();

        $this->assertTrue($this->playlist->syncLiked($this->tracks(6), true));

        // Every track — old and newly liked — is still visited exactly once.
        $seen = ["t0"];
        for ($i = 0; $i < 5; $i++) {
            $seen[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        $this->assertCount(6, array_unique($seen));
    }

    public function testRemoveTracksUnderShuffleKeepsOrderWalkingSurvivors(): void
    {
        $this->playlist->setPlaylist($this->tracks(6), 0, source: "liked");
        $this->playlist->toggleShuffle();

        $this->assertTrue($this->playlist->syncLiked(
            [["hash" => "t1"], ["hash" => "t3"], ["hash" => "t5"]],
            false,
        ));

        $state = $this->playlist->getPlaylist();
        $order = $state["order"];
        $sorted = $order;
        sort($sorted);
        $this->assertSame(range(0, 2), $sorted);

        $walked = [$state["tracks"][$state["index"]]["hash"]];
        for ($i = 0; $i < 2; $i++) {
            $walked[] = $this->playlist->changePlaylistTrack(true)["hash"];
        }
        sort($walked);
        $this->assertSame(["t0", "t2", "t4"], $walked);
    }

    /**
     * Regression: a queue-row click sets the index outside the shuffle walk.
     * Keeping the stale order meant the walk resumed from wherever that track
     * sat in it — and if it sat last, the next natural end stopped playback
     * with most of the queue unplayed.
     */
    public function testJumpingToTrackLastInShuffleOrderKeepsPlaying(): void
    {
        $this->playlist->setPlaylist($this->tracks(8));
        state()->playlist = ["shuffle" => true, "repeat" => "off"];
        // Force the order to be dealt, then jump to its final track.
        $this->playlist->changePlaylistTrack(true, true);
        $order = $this->playlist->getPlaylist()["order"];
        $this->playlist->setPlaylistIndex($order[count($order) - 1]);

        $this->assertTrue($this->playlist->hasNextAuto());

        $walked = [$this->playlist->getPlaylist()["index"]];
        while (($track = $this->playlist->changePlaylistTrack(true, true)) !== false) {
            $walked[] = $this->playlist->getPlaylist()["index"];
            $this->assertNotEmpty($track["hash"]);
        }

        // The jump re-deals the walk, so every track still plays exactly once.
        sort($walked);
        $this->assertSame(range(0, 7), $walked);
    }

    public function testJumpingWithShuffleOffLeavesOrderAlone(): void
    {
        $this->playlist->setPlaylist($this->tracks(4));
        $this->playlist->setPlaylistIndex(2);

        $this->assertNull($this->playlist->getPlaylist()["order"]);
        $this->assertSame("t3", $this->playlist->changePlaylistTrack(true, true)["hash"]);
        $this->assertFalse($this->playlist->changePlaylistTrack(true, true));
    }
}
