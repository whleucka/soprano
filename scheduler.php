<?php 

require_once __DIR__.'/vendor/autoload.php';

use GO\Scheduler;

// Create a new scheduler
$scheduler = new Scheduler();

$jobs = config("paths.jobs");
$logs = config("paths.logs");

// Soprano sync - ingest new tracks, remove orphans
$scheduler->php($jobs . "/soprano_sync.php")
    ->everyMinute(5)
    ->onlyOne()
    ->output($logs . "soprano-sync-" . date("Y-m-d") . ".log", true);

// Soprano artist images - backfill photos for artists sync added (keyless:
// MusicBrainz -> Wikidata/Wikipedia). Only touches unchecked artists, so most
// runs are no-ops;
$scheduler->php($jobs . "/soprano_artist_images.php")
    ->everyMinute(10)
    ->onlyOne()
    ->output($logs . "soprano-artist-images-" . date("Y-m-d") . ".log", true);

// Soprano lyrics - backfill plain lyrics for tracks sync added (keyless:
// LRCLIB exact /api/get). Only touches unchecked rows, so most runs are no-ops.
$scheduler->php($jobs . "/soprano_lyrics.php")
    ->everyMinute(15)
    ->onlyOne()
    ->output($logs . "soprano-lyrics-" . date("Y-m-d") . ".log", true);

// Soprano transcode - warm the Opus cache for lossless tracks sync added and
// prune cache files for removed tracks. Only encodes tracks without a fresh
// cache file, so most runs are no-ops.
$scheduler->php($jobs . "/soprano_transcode.php")
    ->everyMinute(10)
    ->onlyOne()
    ->output($logs . "soprano-transcode-" . date("Y-m-d") . ".log", true);

// Soprano playlists - nightly regeneration of the per-client generated mixes
// (Heavy Rotation, Rediscover, Fresh Arrivals, Time Machine, Morning/Evening
// Mix). Each mix keeps its hash and swaps tracks in place, like a daily mix.
$scheduler->php($jobs . "/soprano_playlists.php")
    ->daily('04:00')
    ->onlyOne()
    ->output($logs . "soprano-playlists-" . date("Y-m-d") . ".log", true);

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
