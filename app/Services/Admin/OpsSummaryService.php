<?php

namespace App\Services\Admin;

/**
 * The one-line "is the box fine" summary.
 *
 * The old dashboard gave operations eight full cards — system health, redis,
 * database, email queue, audit summary, HTTP status, file info, users — most of
 * which duplicated a dedicated admin module that shows the same thing with room
 * to breathe. On a personal music server none of it changes between page loads,
 * so it earned a strip, not half the page.
 *
 * Everything here is deliberately a scalar. DashboardService's equivalents
 * enumerate every table in information_schema and every registered health
 * check to build cards nobody reads; these are the cheap aggregate forms, so
 * the strip costs a handful of queries rather than a full telemetry sweep.
 */
class OpsSummaryService
{
    private ?int $queueDepth = null;
    private ?string $cacheStatus = null;

    public function __construct(private SystemHealthService $health)
    {
    }

    /**
     * Cells for the ops strip, in display order.
     *
     * @return array<int, array{label: string, value: string, icon: string, tone: string, note: ?string, link: ?string}>
     */
    public function getStrip(AnalyticsRange $range): array
    {
        $requests = $this->requests($range);
        $memory = $this->health->getCheck('memory') ?? [];
        $disk = $this->health->getCheck('disk') ?? [];

        return [
            $this->cell(
                'Health',
                ucfirst($this->health->getOverallStatus()),
                'activity',
                $this->tone($this->health->getOverallStatus()),
                'php ' . PHP_VERSION,
                uri('health.admin.index'),
            ),
            $this->cell(
                'Requests',
                number_format($requests['total']),
                'arrow-left-right',
                'info',
                $range->label,
                uri('activity.admin.index'),
            ),
            $this->cell(
                'Errors',
                number_format($requests['errors']),
                'exclamation-triangle',
                $requests['errors'] === 0
                    ? 'success'
                    : ($requests['error_rate'] >= 5 ? 'danger' : 'warning'),
                $requests['error_rate'] . '% of requests',
                uri('activity.admin.index'),
            ),
            $this->cell(
                'Memory',
                ($memory['percent'] ?? 0) . '%',
                'memory',
                $this->tone($memory['status'] ?? 'ok'),
                'limit ' . ini_get('memory_limit'),
                null,
            ),
            $this->cell(
                'Disk',
                ($disk['percent'] ?? 0) . '%',
                'hdd',
                $this->tone($disk['status'] ?? 'ok'),
                isset($disk['free']) ? format_bytes((int) $disk['free']) . ' free' : null,
                null,
            ),
            $this->cell(
                'Database',
                $this->databaseSize(),
                'database',
                'muted',
                null,
                null,
            ),
            $this->cell(
                'Cache',
                $this->cacheStatus(),
                'lightning-charge',
                $this->cacheStatus() === 'Redis' ? 'success' : 'muted',
                config('cache.driver') === 'redis' ? null : 'file driver',
                null,
            ),
            $this->cell(
                'Mail queue',
                number_format($this->queueDepth()),
                'envelope',
                $this->queueDepth() > 0 ? 'warning' : 'success',
                'pending',
                uri('email-queue.admin.index'),
            ),
        ];
    }

    private function cell(
        string $label,
        string $value,
        string $icon,
        string $tone,
        ?string $note,
        ?string $link,
    ): array {
        return compact('label', 'value', 'icon', 'tone', 'note', 'link');
    }

    /**
     * Map a health check's status onto the dashboard's tone vocabulary, so the
     * strip colours the same way the KPI tiles do.
     */
    private function tone(string $status): string
    {
        return match ($status) {
            'ok', 'healthy' => 'success',
            'warning', 'warn', 'degraded' => 'warning',
            'critical', 'error', 'unhealthy' => 'danger',
            default => 'muted',
        };
    }

    /** Request volume and error share over the window. */
    private function requests(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause('created_at');

        $row = db()->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status_code >= 400) AS errors
             FROM activity
             WHERE $where",
            $params
        ) ?: [];

        $total = (int) ($row['total'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);

        return [
            'total' => $total,
            'errors' => $errors,
            'error_rate' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Total database size. One aggregate row out of information_schema rather
     * than the per-table listing the Database module already renders.
     */
    private function databaseSize(): string
    {
        try {
            $row = db()->fetch(
                "SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            );
            return format_bytes((int) ($row['bytes'] ?? 0));
        } catch (\Throwable) {
            return '—';
        }
    }

    private function cacheStatus(): string
    {
        if ($this->cacheStatus !== null) {
            return $this->cacheStatus;
        }

        try {
            if (config('cache.driver') === 'redis' && redis()->isAvailable()) {
                return $this->cacheStatus = 'Redis';
            }
        } catch (\Throwable) {
            // isAvailable() can throw when the extension is missing entirely,
            // which is a working configuration — it just means file cache.
        }
        return $this->cacheStatus = 'File';
    }

    /**
     * Jobs still owed a delivery attempt. `processing` counts as depth — a job
     * stuck in that state is exactly the thing worth noticing, and excluding it
     * would report an empty queue while mail silently piles up.
     */
    private function queueDepth(): int
    {
        if ($this->queueDepth !== null) {
            return $this->queueDepth;
        }

        try {
            $row = db()->fetch(
                "SELECT COUNT(*) AS total FROM email_jobs
                 WHERE status IN ('pending', 'processing')"
            );
            return $this->queueDepth = (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return $this->queueDepth = 0;
        }
    }

    /**
     * Requests over the window, split into successful and failed, bucketed the
     * same way every playback chart is — so the ops chart and the listening
     * chart share an x-axis and a spike in one can be lined up against the other.
     */
    public function getRequestsChart(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause('created_at');
        [$sqlBucket, $phpFormat] = $range->bucketExpression('created_at');

        $rows = db()->fetchAll(
            "SELECT
                $sqlBucket AS bucket,
                COUNT(*) AS total,
                SUM(status_code >= 400) AS errors
             FROM activity
             WHERE $where
             GROUP BY bucket
             ORDER BY bucket",
            $params
        );

        $byBucket = [];
        foreach ($rows as $row) {
            $byBucket[(string) $row['bucket']] = [
                'ok' => (int) $row['total'] - (int) $row['errors'],
                'errors' => (int) $row['errors'],
            ];
        }

        $labels = [];
        $ok = [];
        $errors = [];
        $cursor = $range->firstBucket($this->earliestActivity());
        $end = new \DateTimeImmutable($range->until);
        $step = $range->bucketStep();

        for ($i = 0; $i < 400 && $cursor <= $end; $i++) {
            $bucket = $byBucket[$cursor->format($phpFormat)] ?? ['ok' => 0, 'errors' => 0];
            $labels[] = $range->bucketLabel($cursor);
            $ok[] = $bucket['ok'];
            $errors[] = $bucket['errors'];
            $cursor = $cursor->modify($step);
        }

        return [
            'type' => 'bar',
            'labels' => $labels,
            'stacked' => true,
            'datasets' => [
                ['label' => 'OK', 'data' => $ok, 'role' => 'info', 'axis' => 'y'],
                ['label' => 'Errors', 'data' => $errors, 'role' => 'danger', 'axis' => 'y'],
            ],
        ];
    }

    /**
     * Unique IPs per country over the window, for the world map.
     *
     * Lifted off DashboardService, which took its own `today|7d|30d|year`
     * string. That gave the map a second, incompatible range vocabulary and a
     * second selector on the page — and once the dashboard grew a shared
     * `?range=`, the two fought over the same query parameter.
     */
    public function getCountryActivity(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause('created_at');

        $rows = db()->fetchAll(
            "SELECT country_code, COUNT(DISTINCT ip) AS count
             FROM activity
             WHERE country_code IS NOT NULL AND $where
             GROUP BY country_code
             ORDER BY count DESC",
            $params
        );

        $countries = [];
        $max = 1;
        $total = 0;

        foreach ($rows as $row) {
            $code = strtoupper($row['country_code']);
            $count = (int) $row['count'];
            $countries[$code] = $count;
            $total += $count;
            $max = max($max, $count);
        }

        return [
            'countries' => $countries,
            'countries_json' => json_encode($countries),
            'max' => $max,
            'total' => $total,
            'country_count' => count($countries),
            // Flags for the legend; two-letter codes only, so a malformed row
            // renders as a bare code rather than a broken flag sprite.
            'top' => array_map(
                fn(string $code, int $count) => [
                    'code' => $code,
                    'flag' => strlen($code) === 2
                        ? sprintf('<span class="fi fi-%s"></span>', strtolower($code))
                        : '',
                    'count' => number_format($count),
                ],
                array_slice(array_keys($countries), 0, 10),
                array_slice(array_values($countries), 0, 10),
            ),
        ];
    }

    private function earliestActivity(): ?string
    {
        $row = db()->fetch("SELECT MIN(created_at) AS at FROM activity");
        return $row['at'] ?? null;
    }
}
