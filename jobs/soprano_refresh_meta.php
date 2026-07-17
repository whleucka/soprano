<?php

// Re-reads tags for every track already in the library and updates artist/
// album links and tag-derived meta in place. Track ids are preserved, so
// likes, playlists, and play history survive. Run manually after retagging
// files (e.g. fixing "VA" artist tags) — not scheduled.

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\SyncService;

$service = container()->get(SyncService::class);
$result = $service->refreshMetadata();

printf(
    "%s soprano_refresh_meta: scanned=%d updated=%d missing=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->scanned,
    $result->updated,
    $result->missing,
    $result->failed,
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
