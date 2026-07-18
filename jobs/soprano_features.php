<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\TrackFeaturesService;

$service = container()->get(TrackFeaturesService::class);
$result  = $service->backfill();

printf(
    "%s soprano_features: checked=%d analyzed=%d failed=%d%s%s\n",
    date('Y-m-d H:i:s'),
    $result->checked,
    $result->analyzed,
    $result->failed,
    $result->available ? '' : ' skipped=extractor-unavailable',
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
