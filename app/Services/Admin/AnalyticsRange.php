<?php

namespace App\Services\Admin;

use Echo\Framework\Admin\WidgetRange;

/**
 * The dashboard's time window.
 *
 * Every analytics widget takes one of these instead of hard-coding its own
 * window, which is what let the old dashboard drift into four separate
 * "requests" charts (today / week / month / YTD) that all showed the same
 * metric. One range, selected once at the top of the page, drives everything.
 *
 * The range also carries the *previous* equal-length window so a KPI can show
 * a delta without every caller re-deriving "the 7 days before these 7 days".
 * `all` has no previous window — deltas are suppressed rather than compared
 * against a partial period, which would read as a collapse in traffic.
 */
final class AnalyticsRange implements WidgetRange
{
    public const DEFAULT = '7d';

    /** Selector order, and the only accepted keys. */
    public const OPTIONS = [
        '24h' => '24 hours',
        '7d'  => '7 days',
        '30d' => '30 days',
        '90d' => '90 days',
        'all' => 'All time',
    ];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $since,
        public readonly string $until,
        public readonly ?string $prevSince,
        public readonly ?string $prevUntil,
        /** hour | day | week | month */
        public readonly string $bucket,
    ) {
    }

    /**
     * Resolve a range key, falling back to the default rather than throwing —
     * this comes off a query string, so a stale bookmark shouldn't 500.
     */
    public static function from(?string $key, ?string $earliest = null): self
    {
        $key = isset(self::OPTIONS[$key]) ? $key : self::DEFAULT;
        $now = new \DateTimeImmutable('now');
        $until = $now->format('Y-m-d H:i:s');

        if ($key === 'all') {
            // No lower bound: the queries drop the WHERE entirely. Bucket by
            // month once the library has more than ~6 months of history, or a
            // year of weekly buckets crowds the axis into illegibility.
            $span = $earliest
                ? (int) $now->diff(new \DateTimeImmutable($earliest))->days
                : 0;
            return new self(
                key: 'all',
                label: self::OPTIONS['all'],
                since: null,
                until: $until,
                prevSince: null,
                prevUntil: null,
                bucket: $span > 180 ? 'month' : 'week',
            );
        }

        [$interval, $bucket] = match ($key) {
            '24h' => ['-24 hours', 'hour'],
            '7d'  => ['-7 days', 'day'],
            '30d' => ['-30 days', 'day'],
            '90d' => ['-90 days', 'week'],
        };

        $since = $now->modify($interval);
        // The previous window is the same length, ending where this one starts.
        $length = $now->getTimestamp() - $since->getTimestamp();
        $prevSince = $since->modify("-{$length} seconds");

        return new self(
            key: $key,
            label: self::OPTIONS[$key],
            since: $since->format('Y-m-d H:i:s'),
            until: $until,
            prevSince: $prevSince->format('Y-m-d H:i:s'),
            prevUntil: $since->format('Y-m-d H:i:s'),
            bucket: $bucket,
        );
    }

    public function rangeKey(): string
    {
        return $this->key;
    }

    public function rangeLabel(): string
    {
        return $this->label;
    }

    public function hasPrevious(): bool
    {
        return $this->prevSince !== null;
    }

    /**
     * A `created_at >= ?` clause plus its bindings, or an always-true clause
     * for `all`. Returned as a pair so callers can splice it into a larger
     * WHERE without branching on `since === null` themselves.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public function clause(string $column = 'tp.created_at'): array
    {
        if ($this->since === null) {
            return ['1 = 1', []];
        }
        return ["$column >= ?", [$this->since]];
    }

    /** @return array{0: string, 1: array<int, string>} */
    public function previousClause(string $column = 'tp.created_at'): array
    {
        if (!$this->hasPrevious()) {
            return ['1 = 0', []];
        }
        return ["$column >= ? AND $column < ?", [$this->prevSince, $this->prevUntil]];
    }

    /**
     * MySQL expression that collapses a datetime to this range's bucket, and
     * the PHP format that produces the same string — the pair has to agree or
     * the series comes back all zeroes with no error to explain it.
     *
     * @return array{0: string, 1: string}
     */
    public function bucketExpression(string $column = 'created_at'): array
    {
        return match ($this->bucket) {
            'hour'  => ["DATE_FORMAT($column, '%Y-%m-%d %H:00')", 'Y-m-d H:00'],
            'day'   => ["DATE($column)", 'Y-m-d'],
            // Monday-anchored so weeks line up with the app's ISO week logic.
            'week'  => ["DATE(DATE_SUB($column, INTERVAL WEEKDAY($column) DAY))", 'Y-m-d'],
            'month' => ["DATE_FORMAT($column, '%Y-%m-01')", 'Y-m-01'],
        };
    }

    /** How far to step when walking the buckets. */
    public function bucketStep(): string
    {
        return match ($this->bucket) {
            'hour'  => '+1 hour',
            'day'   => '+1 day',
            'week'  => '+1 week',
            'month' => '+1 month',
        };
    }

    /** Human label for a bucket on the chart axis. */
    public function bucketLabel(\DateTimeImmutable $at): string
    {
        return match ($this->bucket) {
            'hour'  => $at->format('H:00'),
            'day'   => $at->format('D j M'),
            'week'  => 'wk ' . $at->format('j M'),
            'month' => $at->format('M Y'),
        };
    }

    /**
     * The first bucket of the window, snapped to a bucket boundary so a
     * partial leading bucket doesn't shift every label by a few minutes.
     */
    public function firstBucket(?string $earliest = null): \DateTimeImmutable
    {
        $start = new \DateTimeImmutable($this->since ?? ($earliest ?: 'now'));
        return match ($this->bucket) {
            'hour'  => $start->setTime((int) $start->format('H'), 0),
            'day'   => $start->setTime(0, 0),
            'week'  => $start->modify('monday this week')->setTime(0, 0),
            'month' => $start->modify('first day of this month')->setTime(0, 0),
        };
    }
}
