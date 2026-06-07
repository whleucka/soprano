<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\SyncService;

$path = config('soprano.music_path');

if (!file_exists($path)) {
    printf("%s soprano_sync: path does not exist: %s\n", date('Y-m-d H:i:s'), $path);
    exit(1);
}

$service = container()->get(SyncService::class);
$result = $service->sync($path);

printf(
    "%s soprano_sync: scanned=%d inserted=%d removed=%d skipped=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->scanned,
    $result->inserted,
    $result->removed,
    $result->skipped,
    $result->failed,
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
