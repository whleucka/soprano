<?php

namespace App\Providers;

use App\Admin\Widgets\ActivityMapWidget;
use App\Admin\Widgets\LibraryGrowthWidget;
use App\Admin\Widgets\ListenersWidget;
use App\Admin\Widgets\ListeningClockWidget;
use App\Admin\Widgets\ListeningKpiWidget;
use App\Admin\Widgets\ListeningTrendWidget;
use App\Admin\Widgets\OpsStripWidget;
use App\Admin\Widgets\PlaysBySourceWidget;
use App\Admin\Widgets\RequestsTrendWidget;
use App\Admin\Widgets\SourceQualityWidget;
use App\Admin\Widgets\TopAlbumsWidget;
use App\Admin\Widgets\TopArtistsWidget;
use App\Admin\Widgets\TopTracksWidget;
use Echo\Framework\Admin\WidgetRegistry;
use Echo\Framework\Support\ServiceProvider;

/**
 * Widget Service Provider
 *
 * Registers the dashboard widgets. Each widget declares its own band ($group)
 * and its position within it ($priority); the dashboard renders bands in a
 * fixed order, so registration order here doesn't matter.
 */
class WidgetServiceProvider extends ServiceProvider
{
    /**
     * Register widgets
     */
    public function register(): void
    {
        // Band 1 — the headline strip.
        WidgetRegistry::register('listening-kpis', ListeningKpiWidget::class);

        // Band 2 — listening. Widths pair up to 12 per row: 8+4, 6+6, 4+4+4, 6+6.
        WidgetRegistry::register('listening-trend', ListeningTrendWidget::class);
        WidgetRegistry::register('plays-by-source', PlaysBySourceWidget::class);
        WidgetRegistry::register('source-quality', SourceQualityWidget::class);
        WidgetRegistry::register('listening-clock', ListeningClockWidget::class);
        WidgetRegistry::register('top-tracks', TopTracksWidget::class);
        WidgetRegistry::register('top-artists', TopArtistsWidget::class);
        WidgetRegistry::register('top-albums', TopAlbumsWidget::class);
        WidgetRegistry::register('library-growth', LibraryGrowthWidget::class);
        WidgetRegistry::register('listeners', ListenersWidget::class);

        // Band 3 — operations, collapsed to a strip plus one traffic chart.
        WidgetRegistry::register('ops-strip', OpsStripWidget::class);
        WidgetRegistry::register('requests-trend', RequestsTrendWidget::class);
        WidgetRegistry::register('activity-map', ActivityMapWidget::class);

        /*
         * Deliberately NOT registered, though the classes and templates are
         * still here: system-health, redis, database, email-queue,
         * audit-summary, http-status, file-info, users, activity-heatmap.
         *
         * Every one of them duplicated an admin module that shows the same
         * data with room to breathe, and between them they filled the page
         * above the analytics. OpsStripWidget carries the one number each was
         * actually consulted for, and links through to the owning module.
         *
         * They're one line each to bring back if a strip cell turns out to be
         * too little — which is why they weren't deleted.
         */
    }

    /**
     * Bootstrap widget services
     */
    public function boot(): void
    {
        // Any widget initialization can go here
    }
}
