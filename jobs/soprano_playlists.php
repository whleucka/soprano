<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Soprano\AutoPlaylistService;

$service = container()->get(AutoPlaylistService::class);
$clients = $service->activeClientIds();

if (empty($clients)) {
    printf("%s soprano_playlists: no clients with listening history\n", date('Y-m-d H:i:s'));
    exit(0);
}

$failed = 0;
foreach ($clients as $clientId) {
    try {
        $result = $service->generateForClient($clientId);
        $summary = implode(' ', array_map(
            fn($slot, $count) => "$slot=$count",
            array_keys($result),
            $result,
        ));
        printf("%s soprano_playlists: client=%d %s\n", date('Y-m-d H:i:s'), $clientId, $summary);
    } catch (\Throwable $e) {
        $failed++;
        fprintf(
            STDERR,
            "%s soprano_playlists: client=%d error — %s\n",
            date('Y-m-d H:i:s'),
            $clientId,
            $e->getMessage(),
        );
    }
}

exit($failed === 0 ? 0 : 1);
