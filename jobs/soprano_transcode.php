<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\TranscodeService;

$service = container()->get(TranscodeService::class);
$result  = $service->backfill();

printf(
    "%s soprano_transcode: checked=%d encoded=%d skipped=%d failed=%d pruned=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->checked,
    $result->encoded,
    $result->skipped,
    $result->failed,
    $result->pruned,
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
