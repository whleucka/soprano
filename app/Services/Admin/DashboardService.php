<?php

namespace App\Services\Admin;

use App\Models\Activity;
use App\Models\Audit;
use App\Models\EmailJob;
use App\Models\FileInfo;
use App\Models\Module;
use App\Models\User;

class DashboardService
{
    /**
     * Request-level cache for expensive queries called by multiple widgets
     */
    private array $cache = [];

    public function __construct(private SystemHealthService $healthService)
    {
    }

    /**
     * Get a cached value or compute and cache it
     */
    private function cached(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $callback();
        }
        return $this->cache[$key];
    }

    /**
     * Get current date/time (in the app timezone configured at bootstrap)
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    public function getUsersCount(): int
    {
        return $this->cached('users_count', fn() => User::countAll());
    }

    public function getActiveUsersCount(): int
    {
        return $this->cached('active_users_count', function () {
            $now = $this->now()->format('Y-m-d H:i:s');
            $threshold = date('Y-m-d H:i:s', strtotime('-30 minutes', strtotime($now)));
            return Activity::where('created_at', '>=', $threshold)->count('DISTINCT user_id');
        });
    }

    public function getModulesCount(): int
    {
        return (new Module())->whereNotNull('parent_id')->count();
    }

    public function getTotalRequests(): int
    {
        return Activity::countAll();
    }

    public function getTodayRequests(): int
    {
        $now = $this->now();
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayEnd = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        return Activity::query()
            ->whereBetween('created_at', $todayStart, $todayEnd)
            ->count();
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealth(): array
    {
        $memory = $this->healthService->getCheck('memory');
        $disk = $this->healthService->getCheck('disk');
        $phpVersion = $this->healthService->getCheck('php_version');

        return [
            'php_version' => $phpVersion['current'] ?? PHP_VERSION,
            'memory' => [
                'usage' => $memory['message'] ?? 'N/A',
                'limit' => ini_get('memory_limit'),
                'percent' => $memory['percent'] ?? 0,
                'status' => $memory['status'] ?? 'ok',
            ],
            'disk' => [
                'free' => $disk['free'] ?? 0,
                'total' => $disk['total'] ?? 0,
                'used' => $disk['used'] ?? 0,
                'percent' => $disk['percent'] ?? 0,
                'status' => $disk['status'] ?? 'ok',
            ],
            'uptime' => $this->getUptime(),
        ];
    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats(): array
    {
        try {
            $tables = db()->fetchAll(
                "SELECT
                    table_name,
                    COALESCE(table_rows, 0) AS table_rows,
                    ROUND(COALESCE(data_length, 0) / 1024 / 1024, 2) AS data_size_mb,
                    ROUND(COALESCE(index_length, 0) / 1024 / 1024, 2) AS index_size_mb
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                    AND table_type = 'BASE TABLE'
                ORDER BY table_rows DESC"
            );
        } catch (\Throwable $e) {
            error_log('getDatabaseStats failed: ' . $e->getMessage());
            $tables = [];
        }

        $totalRows = 0;
        $totalSize = 0;
        $tableCount = count($tables);

        foreach ($tables as $table) {
            $totalRows += (int)$table['table_rows'];
            $totalSize += (float)$table['data_size_mb'] + (float)$table['index_size_mb'];
        }

        return [
            'table_count' => $tableCount,
            'total_rows' => number_format($totalRows),
            'total_size' => number_format($totalSize, 2) . ' MB',
            'tables' => array_slice($tables, 0, 10),
        ];
    }

    /**
     * Get audit activity summary
     */
    public function getAuditSummary(): array
    {
        return $this->cached('audit_summary', function () {
            $today = $this->now()->format('Y-m-d');
            $sevenDaysAgo = $this->now()->modify('-7 days')->format('Y-m-d H:i:s');

            // Use ORM for simple date-based count
            $todayCount = Audit::query()
                ->whereBetween('created_at', $today . ' 00:00:00', $today . ' 23:59:59')
                ->count();

            // GROUP BY queries still use raw SQL (analytics-specific)
            $byEvent = db()->fetchAll(
                "SELECT event, COUNT(*) as count
                FROM audits
                WHERE created_at >= ?
                GROUP BY event",
                [$sevenDaysAgo]
            );

            $byModel = db()->fetchAll(
                "SELECT auditable_type, COUNT(*) as count
                FROM audits
                WHERE created_at >= ?
                GROUP BY auditable_type
                ORDER BY count DESC
                LIMIT 5",
                [$sevenDaysAgo]
            );

            $eventCounts = ['created' => 0, 'updated' => 0, 'deleted' => 0];
            foreach ($byEvent as $row) {
                $eventCounts[$row['event']] = (int)$row['count'];
            }

            $modelCounts = [];
            foreach ($byModel as $row) {
                $modelCounts[$row['auditable_type']] = (int)$row['count'];
            }

            // Use ORM for recent audits - models can access relations
            $recentAuditModels = Audit::query()->latest()->get(5);

            $recent = [];
            foreach ($recentAuditModels as $audit) {
                $user = $audit->user();
                $userName = $user ? trim($user->first_name . ' ' . $user->surname) : 'System';
                $recent[] = [
                    'id' => $audit->id,
                    'event' => $audit->event,
                    'type' => $audit->auditable_type,
                    'record_id' => $audit->auditable_id,
                    'user' => $userName,
                    'time_ago' => $this->timeAgo($audit->created_at),
                ];
            }

            return [
                'today' => (int)$todayCount,
                'by_event' => $eventCounts,
                'by_model' => $modelCounts,
                'recent' => $recent,
            ];
        });
    }

    /**
     * Format time ago string
     */
    private function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'just now';
        }

        $intervals = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
        ];

        foreach ($intervals as $seconds => $label) {
            $count = floor($diff / $seconds);
            if ($count >= 1) {
                return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    /**
     * Get users widget data (totals, recent signups, active now)
     */
    public function getUsersWidgetData(): array
    {
        $totalUsers = $this->getUsersCount();
        $activeUsers = $this->getActiveUsersCount();

        // New users in the last 7 days
        $sevenDaysAgo = $this->now()->modify('-7 days')->format('Y-m-d H:i:s');
        $newUsersWeek = User::where('created_at', '>=', $sevenDaysAgo)->count();

        // New users today
        $today = $this->now()->format('Y-m-d');
        $newUsersToday = User::query()
            ->whereBetween('created_at', $today . ' 00:00:00', $today . ' 23:59:59')
            ->count();

        // Recent users (last 5 signups) - use ORM
        $recentUserModels = User::query()->latest()->get(5);

        $recent = [];
        foreach ($recentUserModels as $user) {
            $recent[] = [
                'id' => $user->id,
                'name' => $user->fullName(),
                'email' => $user->email,
                'time_ago' => $this->timeAgo($user->created_at),
            ];
        }

        return [
            'total' => (int)$totalUsers,
            'active' => (int)$activeUsers,
            'new_week' => (int)$newUsersWeek,
            'new_today' => (int)$newUsersToday,
            'recent' => $recent,
        ];
    }

    /**
     * Get user activity heatmap (7 days x 24 hours)
     */
    public function getUserActivityHeatmap(): array
    {
        $now = $this->now();
        $weekAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');

        $data = db()->fetchAll(
            "SELECT
                DAYOFWEEK(created_at) as dow,
                HOUR(created_at) as hour,
                COUNT(*) as count
            FROM activity
            WHERE created_at >= ?
            GROUP BY dow, hour",
            [$weekAgo]
        );

        $matrix = [];
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $matrix[$d][$h] = 0;
            }
        }

        $maxCount = 1;
        $totalRequests = 0;
        foreach ($data as $row) {
            $dow = (int)$row['dow'];
            $hour = (int)$row['hour'];
            $count = (int)$row['count'];
            $matrix[$dow][$hour] = $count;
            $totalRequests += $count;
            if ($count > $maxCount) {
                $maxCount = $count;
            }
        }

        return [
            'matrix' => $matrix,
            'days' => $days,
            'hours' => range(0, 23),
            'max' => $maxCount,
            'total_requests' => $totalRequests,
        ];
    }

    /**
     * Get email queue widget data
     */
    public function getEmailQueueData(): array
    {
        $now = $this->now();
        $today = $now->format('Y-m-d');
        $sevenDaysAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');

        // Counts by status - GROUP BY still uses raw SQL (analytics-specific)
        $statusCounts = db()->fetchAll(
            "SELECT status, COUNT(*) as count FROM email_jobs GROUP BY status"
        );

        $statuses = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'exhausted' => 0];
        foreach ($statusCounts as $row) {
            $statuses[$row['status']] = (int)$row['count'];
        }

        // Sent today - use ORM
        $sentToday = EmailJob::where('status', 'sent')
            ->whereBetween('sent_at', $today . ' 00:00:00', $today . ' 23:59:59')
            ->count();

        // Failed/exhausted in last 7 days - use ORM with whereRaw for IN clause
        $failedRecent = EmailJob::where('last_attempt_at', '>=', $sevenDaysAgo)
            ->whereRaw("status IN ('failed', 'exhausted')")
            ->count();

        // Recent jobs (last 5) - use ORM
        $recentJobModels = EmailJob::query()->latest()->get(5);

        $recent = [];
        foreach ($recentJobModels as $job) {
            $recent[] = [
                'id' => $job->id,
                'to' => $job->to_address,
                'subject' => $job->subject,
                'status' => $job->status,
                'attempts' => (int)$job->attempts . '/' . (int)$job->max_attempts,
                'time_ago' => $this->timeAgo($job->created_at),
            ];
        }

        return [
            'statuses' => $statuses,
            'total' => array_sum($statuses),
            'sent_today' => (int)$sentToday,
            'failed_recent' => (int)$failedRecent,
            'recent' => $recent,
        ];
    }

    /**
     * Get system uptime if available
     */
    private function getUptime(): ?string
    {
        $uptime = $this->healthService->getCheck('uptime');
        return $uptime['uptime_formatted'] ?? null;
    }

    /**
     * Get daily report data for the previous day.
     *
     * Aggregates requests, users, audit events, email queue stats,
     * file uploads, and top countries — reusing existing query patterns.
     */
    public function getDailyReportData(): array
    {
        $now = $this->now();
        $yesterday = $now->modify('-1 day');
        $date = $yesterday->format('Y-m-d');
        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';

        // -- Requests --
        $totalRequests = Activity::query()
            ->whereBetween('created_at', $dayStart, $dayEnd)
            ->count();

        $uniqueVisitors = Activity::query()
            ->whereBetween('created_at', $dayStart, $dayEnd)
            ->count('DISTINCT ip');

        // Requests by hour (for sparkline-style summary)
        $hourlyData = db()->fetchAll(
            "SELECT
                HOUR(created_at) AS hour,
                COUNT(*) AS total
            FROM activity
            WHERE created_at BETWEEN ? AND ?
            GROUP BY hour
            ORDER BY hour",
            [$dayStart, $dayEnd]
        );

        $peakHour = 0;
        $peakCount = 0;
        foreach ($hourlyData as $row) {
            if ((int)$row['total'] > $peakCount) {
                $peakCount = (int)$row['total'];
                $peakHour = (int)$row['hour'];
            }
        }

        // -- Users --
        $totalUsers = User::countAll();
        $newUsers = User::query()
            ->whereBetween('created_at', $dayStart, $dayEnd)
            ->count();

        // -- Audit events --
        $auditTotal = Audit::query()
            ->whereBetween('created_at', $dayStart, $dayEnd)
            ->count();

        $auditByEvent = db()->fetchAll(
            "SELECT event, COUNT(*) as count
            FROM audits
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY event",
            [$dayStart, $dayEnd]
        );

        $auditEvents = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        foreach ($auditByEvent as $row) {
            $auditEvents[$row['event']] = (int)$row['count'];
        }

        // -- Email queue --
        $emailsSent = EmailJob::where('status', 'sent')
            ->whereBetween('sent_at', $dayStart, $dayEnd)
            ->count();

        $emailsFailed = EmailJob::query()
            ->whereBetween('last_attempt_at', $dayStart, $dayEnd)
            ->whereRaw("status IN ('failed', 'exhausted')")
            ->count();

        // -- File uploads --
        $uploadsCount = FileInfo::query()
            ->whereBetween('created_at', $dayStart, $dayEnd)
            ->count();

        $uploadsSize = db()->fetch(
            "SELECT COALESCE(SUM(size), 0) as total_size
             FROM file_info
             WHERE created_at >= ? AND created_at <= ?",
            [$dayStart, $dayEnd]
        );

        // -- Top countries --
        $topCountries = db()->fetchAll(
            "SELECT country_code, COUNT(DISTINCT ip) as count
             FROM activity
             WHERE country_code IS NOT NULL
               AND created_at >= ? AND created_at <= ?
             GROUP BY country_code
             ORDER BY count DESC
             LIMIT 5",
            [$dayStart, $dayEnd]
        );

        return [
            'date' => $yesterday->format('l, F j, Y'),
            'date_short' => $date,
            'app_name' => config('app.name') ?? 'Echo',
            'requests' => [
                'total' => (int)$totalRequests,
                'unique_visitors' => (int)$uniqueVisitors,
                'peak_hour' => sprintf('%02d:00', $peakHour),
                'peak_count' => $peakCount,
            ],
            'users' => [
                'total' => (int)$totalUsers,
                'new' => (int)$newUsers,
            ],
            'audit' => [
                'total' => (int)$auditTotal,
                'events' => $auditEvents,
            ],
            'emails' => [
                'sent' => (int)$emailsSent,
                'failed' => (int)$emailsFailed,
            ],
            'uploads' => [
                'count' => (int)$uploadsCount,
                'size' => format_bytes((int)($uploadsSize['total_size'] ?? 0)),
            ],
            'top_countries' => $topCountries,
        ];
    }

    /**
     * Get file info statistics for dashboard widget
     */
    public function getFileInfoStats(): array
    {
        return $this->cached('file_info_stats', function () {
            $today = $this->now()->format('Y-m-d');

            // Today's uploads count
            $todayCount = FileInfo::query()
                ->whereBetween('created_at', $today . ' 00:00:00', $today . ' 23:59:59')
                ->count();

            // Today's total size (raw SQL for SUM)
            $todaySize = db()->fetch(
                "SELECT COALESCE(SUM(size), 0) as total_size
                 FROM file_info
                 WHERE created_at >= ? AND created_at <= ?",
                [$today . ' 00:00:00', $today . ' 23:59:59']
            );

            // File type breakdown (images, documents, other)
            $typeBreakdown = db()->fetchAll(
                "SELECT
                    CASE
                        WHEN mime_type LIKE 'image/%' THEN 'images'
                        WHEN mime_type LIKE 'application/pdf'
                             OR mime_type LIKE 'application/msword%'
                             OR mime_type LIKE 'application/vnd.openxmlformats%'
                             OR mime_type LIKE 'text/%' THEN 'documents'
                        ELSE 'other'
                    END as file_type,
                    COUNT(*) as count
                 FROM file_info
                 WHERE created_at >= ? AND created_at <= ?
                 GROUP BY file_type",
                [$today . ' 00:00:00', $today . ' 23:59:59']
            );

            $types = ['images' => 0, 'documents' => 0, 'other' => 0];
            foreach ($typeBreakdown as $row) {
                $types[$row['file_type']] = (int)$row['count'];
            }

            // Recent uploads (last 5)
            $recentFiles = FileInfo::query()->latest()->get(5);

            $recent = [];
            foreach ($recentFiles as $file) {
                $recent[] = [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => format_bytes((int)$file->size),
                    'mime_type' => $file->mime_type,
                    'time_ago' => $this->timeAgo($file->created_at),
                ];
            }

            return [
                'today_count' => (int)$todayCount,
                'today_size' => format_bytes((int)($todaySize['total_size'] ?? 0)),
                'by_type' => $types,
                'recent' => $recent,
            ];
        });
    }

    /**
     * Get HTTP status code summary for the widget
     */
    public function getHttpStatusSummary(): array
    {
        return $this->cached('http_status_summary', function () {
            $now = $this->now();
            $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $todayEnd = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');

            // Today's counts by status category
            $categories = db()->fetchAll(
                "SELECT
                    CASE
                        WHEN status_code BETWEEN 200 AND 299 THEN '2xx'
                        WHEN status_code BETWEEN 300 AND 399 THEN '3xx'
                        WHEN status_code BETWEEN 400 AND 499 THEN '4xx'
                        WHEN status_code BETWEEN 500 AND 599 THEN '5xx'
                        ELSE 'other'
                    END AS category,
                    COUNT(*) AS total
                FROM activity
                WHERE created_at BETWEEN ? AND ?
                    AND status_code IS NOT NULL
                GROUP BY category",
                [$todayStart, $todayEnd]
            );

            $byCategory = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
            foreach ($categories as $row) {
                if (isset($byCategory[$row['category']])) {
                    $byCategory[$row['category']] = (int)$row['total'];
                }
            }

            // Top error paths (4xx and 5xx) in last 7 days
            $weekStart = $now->modify('-7 days')->setTime(0, 0, 0)
                ->format('Y-m-d H:i:s');

            $topErrors = db()->fetchAll(
                "SELECT uri, status_code, COUNT(*) AS total
                FROM activity
                WHERE created_at BETWEEN ? AND ?
                    AND status_code >= 400
                GROUP BY uri, status_code
                ORDER BY total DESC
                LIMIT 5",
                [$weekStart, $todayEnd]
            );

            // Recent error requests
            $recentErrors = db()->fetchAll(
                "SELECT a.uri, a.status_code, a.created_at, u.first_name, u.surname
                FROM activity a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.created_at BETWEEN ? AND ?
                    AND a.status_code >= 400
                ORDER BY a.created_at DESC
                LIMIT 5",
                [$weekStart, $todayEnd]
            );

            $recent = [];
            foreach ($recentErrors as $row) {
                $user = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
                $recent[] = [
                    'uri' => $row['uri'],
                    'status_code' => (int)$row['status_code'],
                    'user' => $user ?: 'Guest',
                    'time_ago' => $this->timeAgo($row['created_at']),
                ];
            }

            $errorTotal = $byCategory['4xx'] + $byCategory['5xx'];

            return [
                'by_category' => $byCategory,
                'error_total' => $errorTotal,
                'top_errors' => $topErrors,
                'recent' => $recent,
            ];
        });
    }
}
