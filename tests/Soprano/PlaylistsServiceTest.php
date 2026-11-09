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

    // ─── Collaboration ───────────────────────────────────────────

    public function testTheOwnerOwnsTheirPlaylist(): void
    {
        $row = new FakePlaylist();

        $this->assertTrue((new StubPlaylistsService($row))->isOwner($row));
    }

    public function testACollaboratorDoesNotOwnThePlaylist(): void
    {
        $row = new FakePlaylist();

        $this->assertFalse((new StubPlaylistsService($row, clientId: 2))->isOwner($row));
    }

    public function testAJoinedPlaylistIsNotTheCollaboratorsUserPlaylist(): void
    {
        // The lookup hands the row over (they're a member), but renaming,
        // deleting and managing members still belong to the owner.
        $row = new FakePlaylist();

        $this->assertNull((new StubPlaylistsService($row, clientId: 2))->getUserPlaylistByHash("abc123"));
    }

    public function testACollaboratorCannotDeleteThePlaylist(): void
    {
        $row = new FakePlaylist();

        (new StubPlaylistsService($row, clientId: 2))->deletePlaylist("abc123");

        $this->assertFalse($row->deleted);
    }

    public function testACollaboratorCannotRenameThePlaylist(): void
    {
        $row = new FakePlaylist();

        $this->assertNull((new StubPlaylistsService($row, clientId: 2))->renamePlaylist("abc123", "Mine now"));
    }

    public function testACollaboratorCannotMakeAnInviteLink(): void
    {
        // Returns before minting a token or touching the database.
        $row = new FakePlaylist();

        $this->assertNull((new StubPlaylistsService($row, clientId: 2))->createInvite("abc123"));
    }

    public function testAGeneratedMixCannotGetAnInviteLink(): void
    {
        $row       = new FakePlaylist();
        $row->slot = "heavy-rotation";

        $this->assertNull((new StubPlaylistsService($row))->createInvite("abc123"));
    }

    public function testATokenIsThirtyTwoHexCharacters(): void
    {
        $this->assertTrue(PlaylistsService::isTokenShaped(bin2hex(random_bytes(16))));
    }

    /** @return array<string, array{string}> */
    public static function badTokens(): array
    {
        return [
            'empty'      => [''],
            'too short'  => ['abc123'],
            'too long'   => [str_repeat('a', 33)],
            'not hex'    => [str_repeat('g', 32)],
            'sql-ish'    => ["' OR 1=1 -- " . str_repeat('a', 20)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function testAnythingElseIsNotAToken(string $token): void
    {
        $this->assertFalse(PlaylistsService::isTokenShaped($token));
    }

    public function testAJoinedPlaylistRowIsSharedAndNamesItsOwner(): void
    {
        $row = $this->map(['is_owner' => 0, 'owner' => 'kara', 'has_members' => 1]);

        $this->assertTrue($row['shared']);
        $this->assertSame('kara', $row['owner']);
    }

    public function testAnOwnPlaylistWithMembersIsSharedButNamesNoOwner(): void
    {
        $row = $this->map(['is_owner' => 1, 'owner' => 'william', 'has_members' => 1]);

        $this->assertTrue($row['shared']);
        $this->assertNull($row['owner']);
    }

    public function testAnOwnPlaylistWithoutMembersIsNotShared(): void
    {
        $row = $this->map(['is_owner' => 1, 'owner' => 'william', 'has_members' => 0]);

        $this->assertFalse($row['shared']);
        $this->assertNull($row['owner']);
    }

    public function testAVirtualPlaylistRowIsNotShared(): void
    {
        // Liked and Random carry none of the ownership columns.
        $row = $this->map([]);

        $this->assertFalse($row['shared']);
        $this->assertNull($row['owner']);
    }

    private function map(array $extra): array
    {
        $method = new ReflectionMethod(PlaylistsService::class, 'mapPlaylistRow');

        return $method->invoke(new PlaylistsService(), [
            'hash' => 'abc123', 'name' => 'Road Trip', 'track_count' => 3, ...$extra,
        ]);
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

    /** Owned by client 1, the stub's default current client. */
    public function __construct()
    {
        parent::__construct();
        $this->client_id = 1;
    }

    public function delete(): bool
    {
        $this->deleted = true;

        return true;
    }
}

/**
 * PlaylistsService with its one db()-backed lookup answering with a fixed row,
 * as seen by a fixed client: 1 owns the fake row, anyone else is there as a
 * collaborator (the lookup only answers for owners and members).
 */
class StubPlaylistsService extends PlaylistsService
{
    public function __construct(private ?Playlist $row, private int $clientId = 1) {}

    public function getPlaylistByHash(string $hash): ?Playlist
    {
        return $this->row;
    }

    protected function clientId(): int
    {
        return $this->clientId;
    }
}
