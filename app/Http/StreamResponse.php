<?php

namespace App\Http;

use Echo\Framework\Http\Response;

class StreamResponse extends Response
{
    private const INTERNAL_LOCATION = '/_protected/music/';

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

        $musicRoot = rtrim((string)config('soprano.music_path'), '/');
        if ($musicRoot === '' || !str_starts_with($this->filePath, $musicRoot . '/')) {
            http_response_code(404);
            return;
        }

        $relative = substr($this->filePath, strlen($musicRoot) + 1);
        $encoded  = implode('/', array_map('rawurlencode', explode('/', $relative)));

        header('Content-Type: ' . $this->contentType);
        header('Cache-Control: public, max-age=31536000');
        header('X-Accel-Redirect: ' . self::INTERNAL_LOCATION . $encoded);
    }
}
