<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\TranscodeService;

// Repairs Opus cache files that were encoded before the track's Essentia
// loudness row existed, so they got no ReplayGain baked in and play hot (see
// TranscodeService::regain).
//
// The first nightly run cleared 600 in 38 minutes on the 4-core box (~3.8s per
// track, not the 7s first guessed), so it spent its count before its clock. The
// count is now set above what the wall-clock budget can reach, leaving the
// budget as the only guard that normally fires — 711 were left after that run
// and fit inside it, so the backlog ends tonight rather than over four nights.
// Staying under an hour keeps a crashed run inside the scheduler's stale-lock
// window instead of wedging the job forever; the count is only the backstop for
// a run of very short tracks, where the deadline would let it run long.
//
// Once the backlog is gone this is a no-op, and stays one unless a feature
// backfill lands after an encode again.
$limit   = 800;
$seconds = 3000;

$service = container()->get(TranscodeService::class);
$result  = $service->regain($limit, $seconds);

printf(
    "%s soprano_regain: stale=%d encoded=%d skipped=%d failed=%d remaining=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->stale,
    $result->encoded,
    $result->skipped,
    $result->failed,
    $result->remaining,
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
