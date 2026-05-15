<?php

namespace App\Http;

use Echo\Framework\Http\Response;

class StreamResponse extends Response
{
    private const CHUNK_SIZE = 8192;

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
        $size  = filesize($this->filePath);
        $start = 0;
        $end   = $size - 1;
        $status = 200;

        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $reqStart = $m[1] !== '' ? (int)$m[1] : null;
            $reqEnd   = $m[2] !== '' ? (int)$m[2] : null;

            if ($reqStart === null && $reqEnd !== null) {
                // Suffix range: last N bytes
                $start = max(0, $size - $reqEnd);
            } else {
                $start = $reqStart ?? 0;
                if ($reqEnd !== null) {
                    $end = min($reqEnd, $size - 1);
                }
            }

            if ($start > $end || $start >= $size) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                http_response_code(416);
                header("Content-Range: bytes */$size");
                return;
            }
            $status = 206;
        }

        $length = $end - $start + 1;

        while (ob_get_level()) {
            ob_end_clean();
        }

        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        http_response_code($status);
        header("Content-Type: {$this->contentType}");
        header("Accept-Ranges: bytes");
        header("Content-Length: {$length}");
        header("Cache-Control: public, max-age=31536000");
        header("X-Content-Type-Options: nosniff");
        if ($status === 206) {
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            return;
        }

        fseek($handle, $start);
        $remaining = $length;

        while ($remaining > 0 && !feof($handle)) {
            if (connection_aborted()) {
                break;
            }
            $chunk = fread($handle, (int)min(self::CHUNK_SIZE, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }

        fclose($handle);
    }
}
