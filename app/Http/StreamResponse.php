<?php

namespace App\Http;

use Echo\Framework\Http\Response;

class StreamResponse extends Response
{
    private const INTERNAL_LOCATION = '/_protected/music/';
    private const INTERNAL_TRANSCODE = '/_protected/transcode/';

    private const MIME_MAP = [
        'mp3'  => 'audio/mpeg',
        'flac' => 'audio/flac',
        'ogg'  => 'audio/ogg',
        'oga'  => 'audio/ogg',
        'opus' => 'audio/ogg',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'wav'  => 'audio/wav',
        'wma'  => 'audio/x-ms-wma',
        'webm' => 'audio/webm',
    ];

    public function __construct(
        private string $filePath,
        private ?string $contentType = null,
    ) {
        parent::__construct('', 200);

        if ($this->contentType === null) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $this->contentType = self::MIME_MAP[$ext] ?? 'application/octet-stream';
        }
    }

    public function send(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        // The file may live under the music library (original) or the transcode
        // cache (Opus). Each maps to its own internal nginx location.
        $roots = [
            [rtrim((string) config('soprano.music_path'), '/'), self::INTERNAL_LOCATION],
            [rtrim((string) config('soprano.transcode_path'), '/'), self::INTERNAL_TRANSCODE],
        ];

        foreach ($roots as [$root, $location]) {
            if ($root === '' || !str_starts_with($this->filePath, $root . '/')) {
                continue;
            }

            $relative = substr($this->filePath, strlen($root) + 1);
            $encoded  = implode('/', array_map('rawurlencode', explode('/', $relative)));

            header('Content-Type: ' . $this->contentType);
            // Revalidate rather than cache long-term: a stream URL is keyed by
            // track hash, but the bytes behind it can change (raw source vs a
            // newly-warmed Opus transcode). nginx serves the file natively and
            // answers conditional requests with a cheap 304, so this stays fast
            // without ever pinning a stale (or previously un-decodable) response.
            header('Cache-Control: no-cache');
            header('X-Accel-Redirect: ' . $location . $encoded);
            return;
        }

        http_response_code(404);
    }
}
