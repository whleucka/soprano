<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\LyricsService;

// Cap on the nightly synced-lyrics backfill. At LRCLIB's ~1s throttle this is
// roughly a few minutes/run; the back catalog fills over successive nights.
const SYNCED_PER_RUN = 250;

$service = container()->get(LyricsService::class);
$result  = $service->backfill();

printf(
    "%s soprano_lyrics: checked=%d found=%d missed=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->checked,
    $result->found,
    $result->missed,
    $result->failed,
    $result->success ? '' : ' error=' . $result->error,
);

// Backfill synced (LRC) lyrics onto tracks that already have plain lyrics but
// no synced version — the back catalog from before syncedLyrics was stored.
// Bounded per run so it spreads across nights under LRCLIB's rate limit
// instead of one long marathon; the empty-string marker makes it resumable.
$synced = $service->fillSynced(SYNCED_PER_RUN);

printf(
    "%s soprano_lyrics(synced): checked=%d found=%d missed=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    $synced->checked,
    $synced->found,
    $synced->missed,
    $synced->failed,
    $synced->success ? '' : ' error=' . $synced->error,
);

exit($result->success && $synced->success ? 0 : 1);
