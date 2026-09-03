<?php

declare(strict_types=1);

namespace Tests\Soprano;

use App\Services\AppVersionService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two halves of version resolution that can be exercised without a
 * container: what counts as a usable tag, and what `describe()` does when
 * pointed at something that is not a tagged repository. The happy path is
 * covered against a real repository built in a temp directory, because the
 * safe.directory and ownership handling is the part that actually broke.
 */
class AppVersionServiceTest extends TestCase
{
    public function testNormalizeTagAcceptsTheTagShapesThisProjectUses(): void
    {
        $this->assertSame('v1.6.0', AppVersionService::normalizeTag('v1.6.0'));
        $this->assertSame('v1.6.0', AppVersionService::normalizeTag("v1.6.0\n"));
        $this->assertSame('1.6.0', AppVersionService::normalizeTag('1.6.0'));
        $this->assertSame('v1.6', AppVersionService::normalizeTag('v1.6'));
        $this->assertSame('v1.6.0-rc.1', AppVersionService::normalizeTag('v1.6.0-rc.1'));
    }

    /** git prints nothing on failure; shell_exec returns null if it cannot spawn. */
    public function testNormalizeTagRejectsSilenceAndNonVersionTags(): void
    {
        $this->assertNull(AppVersionService::normalizeTag(null));
        $this->assertNull(AppVersionService::normalizeTag(''));
        $this->assertNull(AppVersionService::normalizeTag("\n"));
        $this->assertNull(AppVersionService::normalizeTag('nightly'));
        $this->assertNull(AppVersionService::normalizeTag('wip'));
        $this->assertNull(AppVersionService::normalizeTag('fatal: not a git repository'));
    }

    public function testDescribeReturnsNullWhenThereIsNoRepository(): void
    {
        $this->assertNull(AppVersionService::describe(sys_get_temp_dir()));
        $this->assertNull(AppVersionService::describe('/nonexistent-' . uniqid()));
    }

    public function testDescribeReadsTheLatestTagFromARealRepository(): void
    {
        $root = $this->makeRepo(['v1.0.0', 'v1.6.0']);

        $this->assertSame('v1.6.0', AppVersionService::describe($root));
    }

    /** An untagged checkout is a shallow clone or a fresh branch, not an error. */
    public function testDescribeReturnsNullForAnUntaggedRepository(): void
    {
        $root = $this->makeRepo([]);

        $this->assertNull(AppVersionService::describe($root));
    }

    /**
     * Build a throwaway repository with one commit per tag. Identity and hooks
     * are forced locally so this does not depend on the runner's gitconfig.
     */
    private function makeRepo(array $tags): string
    {
        if (!shell_exec('command -v git 2>/dev/null')) {
            $this->markTestSkipped('git is not available');
        }

        $root = sys_get_temp_dir() . '/soprano-version-' . uniqid();
        mkdir($root);
        register_shutdown_function(fn() => shell_exec('rm -rf ' . escapeshellarg($root)));

        $git = 'git -C ' . escapeshellarg($root)
            . ' -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false';

        shell_exec("$git init -q 2>/dev/null");
        foreach ($tags ?: ['untagged'] as $i => $tag) {
            file_put_contents("$root/file", (string) $i);
            shell_exec("$git add file && $git commit -q --no-verify -m 'c$i' 2>/dev/null");
            if ($tags) {
                shell_exec("$git tag " . escapeshellarg($tag) . ' 2>/dev/null');
            }
        }

        return $root;
    }
}
