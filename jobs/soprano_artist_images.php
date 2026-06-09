<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\ArtistImageService;

$service = container()->get(ArtistImageService::class);
$result  = $service->backfill();

printf(
    "%s soprano_artist_images: checked=%d found=%d missed=%d failed=%d%s\n",
    date('Y-m-d H:i:s'),
    $result->checked,
    $result->found,
    $result->missed,
    $result->failed,
    $result->success ? '' : ' error=' . $result->error,
);

exit($result->success ? 0 : 1);
