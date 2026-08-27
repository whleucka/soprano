<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\TranscodeService;

// Repairs Opus cache files that were encoded before the track's Essentia
// loudness row existed, so they got no ReplayGain baked in and play hot (see
// TranscodeService::regain).
//
// Measured ~7s per track on the 4-core box, so a 50-minute run clears roughly
// 400 and the backlog (1830 as of 2026-08-27) drains in four or five nights.
// The wall-clock budget is the real guard — track lengths vary enough that a
// count alone would overshoot — and staying under an hour keeps a crashed run
// inside the scheduler's stale-lock window instead of wedging the job forever.
// The count is the backstop for the opposite case, a run of very short tracks.
//
// Once the backlog is gone this is a no-op, and stays one unless a feature
// backfill lands after an encode again.
$limit   = 600;
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
