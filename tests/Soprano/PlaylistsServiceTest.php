<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Models\Playlist;
use App\Services\Soprano\PlaylistsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Name handling for saved playlists, plus the gate that keeps a generated mix
 * out of the client's hands. Most of PlaylistsService goes through db()/
 * client(), which this suite has no harness for, so what is covered here is
 * what can be reached without one: what a submitted name becomes before it
 * reaches the column (on create and on rename alike), and what the slot gate
 * does with a row once the lookup has handed one over.
 */
class PlaylistsServiceTest extends TestCase
{
    private function clean(string $name): string
    {
        $method = new ReflectionMethod(PlaylistsService::class, 'cleanName');

        return (string) $method->invoke(new PlaylistsService(), $name);
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame("Christmas", $this->clean("  Christmas \n"));
    }

    public function testKeepsInnerWhitespace(): void
    {
        $this->assertSame("Old Skool Mix", $this->clean("Old Skool Mix"));
    }

    public function testWhitespaceOnlyNameBecomesEmpty(): void
    {
        $this->assertSame("", $this->clean("   \t "));
    }

    public function testKeepsALeadingEmojiIntact(): void
    {
        $this->assertSame("🎄 Christmas", $this->clean(" 🎄 Christmas "));
    }

    public function testKeepsAZeroWidthJoinerSequenceIntact(): void
    {
        // A ZWJ emoji is several code points glued together — the sort of thing
        // a byte-wise trim would happily cut in half.
        $name = "👨‍👩‍👧 Family Road Trip";

        $this->assertSame($name, $this->clean($name));
    }

    public function testEmojiOnlyNameSurvives(): void
    {
        $this->assertSame("🎄", $this->clean(" 🎄 "));
    }

    public function testDeletesAPlaylistTheClientMade(): void
    {
        $row = new FakePlaylist();

        (new StubPlaylistsService($row))->deletePlaylist("abc123");

        $this->assertTrue($row->deleted);
    }

    public function testDoesNotDeleteAGeneratedMix(): void
    {
        $row       = new FakePlaylist();
        $row->slot = "heavy-rotation";

        (new StubPlaylistsService($row))->deletePlaylist("abc123");

        $this->assertFalse($row->deleted);
    }

    public function testAGeneratedMixIsNotAUserPlaylist(): void
    {
        $row       = new FakePlaylist();
        $row->slot = "morning-mix";

        $this->assertNull((new StubPlaylistsService($row))->getUserPlaylistByHash("abc123"));
    }

    public function testAPlaylistWithoutASlotIsAUserPlaylist(): void
    {
        $row = new FakePlaylist();

        $this->assertSame($row, (new StubPlaylistsService($row))->getUserPlaylistByHash("abc123"));
    }
}

// ─── Fixtures ────────────────────────────────────────────────

/**
 * A `playlists` row that never reaches the database: Model's constructor only
 * touches it when handed an id, and `slot` is a plain attribute, so the gate
 * can be exercised on a hand-made row. delete() records the call rather than
 * issuing one — which is also the assertion, since a mix must not get that far.
 */
class FakePlaylist extends Playlist
{
    public bool $deleted = false;

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

/** PlaylistsService with its one db()-backed lookup answering with a fixed row. */
class StubPlaylistsService extends PlaylistsService
{
    public function __construct(private ?Playlist $row) {}

    public function getPlaylistByHash(string $hash): ?Playlist
    {
        return $this->row;
    }
}
