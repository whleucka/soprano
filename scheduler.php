<?php 

require_once __DIR__.'/vendor/autoload.php';

use GO\Scheduler;

// Create a new scheduler
$scheduler = new Scheduler();

$jobs = config("paths.jobs");
$logs = config("paths.logs");

// onlyOne() skips a job while its lock file exists, but a php container
// restart mid-run kills the job before the lock is removed — which would
// silently disable that job forever. Treat locks older than an hour as
// orphaned and run anyway (no legitimate job runs that long).
$stale_lock = fn ($lock_time) => (time() - $lock_time) > 3600;

// Soprano sync - ingest new tracks, remove orphans
$scheduler->php($jobs . "/soprano_sync.php")
    ->everyMinute(5)
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-sync-" . date("Y-m-d") . ".log", true);

// Soprano artist images - backfill photos for artists sync added (keyless:
// MusicBrainz -> Wikidata/Wikipedia). Only touches unchecked artists, so most
// runs are no-ops;
$scheduler->php($jobs . "/soprano_artist_images.php")
    ->everyMinute(10)
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-artist-images-" . date("Y-m-d") . ".log", true);

// Soprano lyrics - backfill plain lyrics for tracks sync added (keyless:
// LRCLIB exact /api/get). Only touches unchecked rows, so most runs are no-ops.
$scheduler->php($jobs . "/soprano_lyrics.php")
    ->everyMinute(15)
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-lyrics-" . date("Y-m-d") . ".log", true);

// Soprano transcode - warm the Opus cache for lossless tracks sync added and
// prune cache files for removed tracks. Only encodes tracks without a fresh
// cache file, so most runs are no-ops.
$scheduler->php($jobs . "/soprano_transcode.php")
    ->everyMinute(10)
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-transcode-" . date("Y-m-d") . ".log", true);

// Soprano regain - re-encode cached Opus files that were built before the
// track's loudness feature existed and so carry no ReplayGain (they play ~10dB
// hot). Capped at 50 minutes per run, drains over four or five nights, then
// no-ops. At 01:00 it is well clear of the 03:00 sitemap and 04:00 playlists.
$scheduler->php($jobs . "/soprano_regain.php")
    ->daily('01:00')
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-regain-" . date("Y-m-d") . ".log", true);

// Soprano playlists - nightly regeneration of the per-client generated mixes
// (Heavy Rotation, Rediscover, Fresh Arrivals, Time Machine, Morning/Evening
// Mix). Each mix keeps its hash and swaps tracks in place, like a daily mix.
$scheduler->php($jobs . "/soprano_playlists.php")
    ->daily('04:00')
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-playlists-" . date("Y-m-d") . ".log", true);

// Soprano features - backfill audio features (BPM, danceability, key, energy)
// via bin/essentia_extract.py for station queries. Keyless, CPU-only; batches
// of 100/run (~15s per track), no-op once the library is analyzed or when the
// extractor isn't installed.
$scheduler->php($jobs . "/soprano_features.php")
    ->everyMinute(30)
    ->onlyOne(null, $stale_lock)
    ->output($logs . "soprano-features-" . date("Y-m-d") . ".log", true);

// Mail worker - process queued emails
$scheduler->php($jobs . "/mail_worker.php")
    ->everyMinute()
    ->output($logs . "mail-worker-" . date("Y-m-d") . ".log", true);

// Daily report - send summary email at 6:00 AM
$scheduler->php($jobs . "/daily_report.php")
    ->daily('06:00')
    ->output($logs . "daily-report-" . date("Y-m-d") . ".log", true);

// Log cleanup - remove log files older than 7 days
$scheduler->php($jobs . "/log_cleanup.php")
    ->daily('00:00')
    ->output($logs . "log-cleanup-" . date("Y-m-d") . ".log", true);

// Sitemap - regenerate public/sitemap.xml daily
$scheduler->php($jobs . "/sitemap_generate.php")
    ->daily('03:00')
    ->output($logs . "sitemap-generate-" . date("Y-m-d") . ".log", true);

// Let the scheduler execute jobs which are due.
$scheduler->run();
