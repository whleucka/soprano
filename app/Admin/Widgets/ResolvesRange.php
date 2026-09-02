<?php

namespace App\Admin\Widgets;

use App\Services\Admin\AnalyticsRange;

/**
 * Narrows Widget::$range from Echo's WidgetRange interface — which carries only
 * a key and a label — to the AnalyticsRange these widgets query with.
 */
trait ResolvesRange
{
    /**
     * The active window, or the default one.
     *
     * The fallback covers a widget rendered from its own refresh URL with no
     * `?range=`: a stale htmx target, or someone hitting the endpoint directly.
     * Defaulting beats throwing — the card shows the last 7 days instead of
     * taking the whole band down with it.
     *
     * No `earliest` argument is needed here: it only affects bucket choice for
     * the `all` window, and the fallback is never `all`.
     */
    protected function range(): AnalyticsRange
    {
        return $this->range instanceof AnalyticsRange
            ? $this->range
            : AnalyticsRange::from(null);
    }
}
