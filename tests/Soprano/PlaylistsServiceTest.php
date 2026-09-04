<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\Soprano\PlaylistsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Name handling for saved playlists. Everything else on PlaylistsService goes
 * through db()/client(), which this suite has no harness for, so what is
 * covered here is the one pure step: what a submitted name becomes before it
 * reaches the column, on create and on rename alike.
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
}
