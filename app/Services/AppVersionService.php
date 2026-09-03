<?php

namespace App\Services;

/**
 * Resolves the version of Soprano that is actually deployed.
 *
 * APP_VERSION in .env has read "v0.0.1" since the project began and nothing
 * bumps it; the real version is the git tag series, v1.6.0 at the time of
 * writing. Both compose files bind-mount the repo root into the php container,
 * so .git is present at runtime and the tag is an honest source.
 *
 * The git call is less obvious than it looks: the mount is owned by the host
 * user while php-fpm workers run as www-data, so plain git refuses outright
 * with "detected dubious ownership". Passing safe.directory with -c clears
 * that per invocation, which beats writing a gitconfig into a container whose
 * web user has no writable HOME.
 *
 * Deliberately not cached. It is a process spawn, but it measures 4.3ms in the
 * php container against this repo, on one page that already runs six aggregate
 * queries for the listening stats and that nobody loads in a loop. Caching it
 * would cost more than it saves: `cache:clear` only clears the template, route
 * and widget caches, and restarting php does not touch redis or storage/cache,
 * so a cached tag would survive a deploy and show the previous version for the
 * length of the TTL -- the exact failure this is meant to fix.
 *
 * Whenever git cannot answer -- a source export with no .git, a slimmed image
 * with no git binary, a shallow clone carrying no tags -- this falls back to
 * config("app.version"), so the page always renders something.
 */
class AppVersionService
{
    /** The deployed version: the latest git tag, else the configured value. */
    public function version(): string
    {
        return self::describe(self::root()) ?? (string) config("app.version");
    }

    /**
     * Latest tag reachable from HEAD in the repository at $root, or null if
     * this is not a working repository or git declines to answer.
     */
    public static function describe(string $root): ?string
    {
        if (!is_dir($root . "/.git")) {
            return null;
        }

        $cmd = sprintf(
            "git -c %s -C %s describe --tags --abbrev=0 2>/dev/null",
            escapeshellarg("safe.directory=" . $root),
            escapeshellarg($root),
        );

        return self::normalizeTag(shell_exec($cmd));
    }

    /**
     * Accept only something that reads as a version tag. git prints nothing on
     * failure and shell_exec returns null when it cannot spawn at all, but a
     * repo could equally be tagged "nightly" or "wip" -- none of which belong
     * on a profile page, and all of which should fall through to the config.
     */
    public static function normalizeTag(?string $raw): ?string
    {
        $tag = trim((string) $raw);

        return preg_match('/^v?\d+\.\d+(\.\d+)?([-+][0-9A-Za-z.\-]+)?$/', $tag) === 1
            ? $tag
            : null;
    }

    /** Repository root: two levels up from app/Services. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
