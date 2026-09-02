<?php

namespace Echo\Framework\Admin;

/**
 * A dashboard time window, as far as the framework is concerned.
 *
 * Widgets are an Echo concern and time ranges are an app concern, so the
 * framework deliberately knows almost nothing about them: it needs a stable
 * key to discriminate the widget HTML cache (otherwise a "7 days" render is
 * served to someone asking for "24 hours") and a label to print in the card
 * header. How the window is computed, bucketed, or compared against a previous
 * period stays in the app — see App\Services\Admin\AnalyticsRange.
 */
interface WidgetRange
{
    /** Short stable identifier, e.g. `7d`. Must be safe in a cache key. */
    public function rangeKey(): string;

    /** Human label for the card header, e.g. `7 days`. */
    public function rangeLabel(): string;
}
