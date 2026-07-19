<?php

namespace App\Services\Soprano;

/**
 * Backfills audio features (BPM, danceability, key, energy, loudness) for
 * station queries by piping track pathnames through bin/essentia_extract.py:
 *
 *   tracks missing a track_features row
 *      └─ essentia_extract.py (batch over stdin/stdout, JSON lines)
 *          └─ track_features insert (or an error row, so misses aren't retried)
 *
 * Essentia is keyless and CPU-only. The interpreter comes from the
 * ESSENTIA_PYTHON env var (the php image ships a venv at /opt/essentia);
 * when it's missing the run reports skipped=unavailable rather than failing,
 * so the job is a no-op on hosts without the extractor installed.
 *
 * Runs from the scheduler with no session — nothing here may use client().
 */
class TrackFeaturesService
{
    private const DEFAULT_PYTHON = '/opt/essentia/bin/python';

    /** Per-run cap: ~15s/track on prod, so 100 keeps a run under ~25 min —
     * inside the 30-min schedule and the scheduler's stale-lock window. */
    private const DEFAULT_LIMIT = 100;

    public function __construct(private ?array $command = null)
    {
        // Injectable for tests; production resolves the interpreter lazily in
        // extractorCommand() so construction never touches the filesystem.
    }

    /**
     * Analyze tracks that have no track_features row yet.
     *
     * @return object{success:bool,available:bool,checked:int,analyzed:int,failed:int,error:?string}
     */
    public function backfill(int $limit = self::DEFAULT_LIMIT): object
    {
        $checked  = 0;
        $analyzed = 0;
        $failed   = 0;

        $command = $this->extractorCommand();
        if ($command === null) {
            return $this->result(true, false, 0, 0, 0, null);
        }

        try {
            $rows = db()->fetchAll(
                "SELECT t.id, t.pathname
                 FROM tracks t
                 LEFT JOIN track_features tf ON tf.track_id = t.id
                 WHERE tf.id IS NULL
                 ORDER BY t.id
                 LIMIT " . max(1, $limit),
            );
            if (!$rows) {
                return $this->result(true, true, 0, 0, 0, null);
            }

            $byPath = [];
            foreach ($rows as $row) {
                $byPath[$row['pathname']] = (int) $row['id'];
            }

            foreach ($this->extract($command, array_keys($byPath)) as $data) {
                $trackId = $byPath[$data['path'] ?? ''] ?? null;
                if ($trackId === null) {
                    continue;
                }
                $checked++;
                empty($data['error']) ? $analyzed++ : $failed++;

                // INSERT IGNORE: a concurrent run (manual vs scheduler) may
                // land the same track first — skip it rather than abort the
                // whole batch on the track_id unique key.
                db()->execute(
                    "INSERT IGNORE INTO track_features
                     (track_id, bpm, danceability, energy, avg_loudness_db,
                      dyn_complexity, key_root, key_scale, key_strength, zcr,
                      extractor, error)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $trackId,
                        $data['bpm'] ?? null,
                        $data['danceability'] ?? null,
                        $data['energy'] ?? null,
                        $data['avg_loudness_db'] ?? null,
                        $data['dyn_complexity'] ?? null,
                        $data['key_root'] ?? null,
                        $data['key_scale'] ?? null,
                        $data['key_strength'] ?? null,
                        $data['zcr'] ?? null,
                        $data['extractor'] ?? 'unknown',
                        $data['error'] ?? null,
                    ],
                );
            }
        } catch (\Throwable $e) {
            return $this->result(false, true, $checked, $analyzed, $failed, $e->getMessage());
        }

        return $this->result(true, true, $checked, $analyzed, $failed, null);
    }

    /**
     * Stream paths through the extractor, yielding one decoded JSON row per
     * analyzed file. Rows are inserted as they arrive, so a crash mid-run
     * keeps everything analyzed so far.
     *
     * @param array<int,string> $command
     * @param array<int,string> $paths
     * @return \Generator<array<string,mixed>>
     */
    private function extract(array $command, array $paths): \Generator
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $spec, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('could not start extractor: ' . implode(' ', $command));
        }

        try {
            fwrite($pipes[0], implode("\n", $paths) . "\n");
            fclose($pipes[0]);

            while (($line = fgets($pipes[1])) !== false) {
                $data = json_decode(trim($line), true);
                if (is_array($data)) {
                    yield $data;
                }
            }
        } finally {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($proc);
            if ($status !== 0) {
                throw new \RuntimeException(
                    'extractor exited with status ' . $status
                    . ($stderr ? ': ' . trim($stderr) : ''),
                );
            }
        }
    }

    /**
     * Extractor invocation, or null when no usable interpreter is installed.
     *
     * @return array<int,string>|null
     */
    private function extractorCommand(): ?array
    {
        if ($this->command !== null) {
            return $this->command;
        }
        $python = getenv('ESSENTIA_PYTHON') ?: self::DEFAULT_PYTHON;
        if (!is_executable($python)) {
            return null;
        }
        return [$python, __DIR__ . '/../../../bin/essentia_extract.py'];
    }

    private function result(
        bool $success,
        bool $available,
        int $checked,
        int $analyzed,
        int $failed,
        ?string $error,
    ): object {
        return (object) compact('success', 'available', 'checked', 'analyzed', 'failed', 'error');
    }
}
