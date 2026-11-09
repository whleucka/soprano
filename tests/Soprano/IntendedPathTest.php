<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Http\Controllers\Soprano\Auth\AuthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After sign-in the client is sent back to the page that bounced them, which
 * is how a playlist invite link survives the sign-in form. The stored path
 * goes into a Location header, so anything a browser would read as another
 * host has to be refused.
 */
class IntendedPathTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function local(): array
    {
        return [
            'invite link' => ['/invite/' . str_repeat('a', 32)],
            'playlist'    => ['/playlists/abc123'],
            'root'        => ['/'],
        ];
    }

    #[DataProvider('local')]
    public function testASameSitePathIsAllowed(string $path): void
    {
        $this->assertTrue(AuthController::isLocalPath($path));
    }

    /** @return array<string, array{string}> */
    public static function offsite(): array
    {
        return [
            'protocol-relative'  => ['//evil.example/'],
            'backslash variant'  => ['/\\evil.example/'],
            'absolute url'       => ['https://evil.example/'],
            'relative'           => ['invite/abc'],
            'empty'              => [''],
        ];
    }

    #[DataProvider('offsite')]
    public function testAnythingThatLeavesTheSiteIsRefused(string $path): void
    {
        $this->assertFalse(AuthController::isLocalPath($path));
    }
}
