<?php

namespace App\Admin\Widgets;

use App\Services\Admin\PlaybackAnalyticsService;
use Echo\Framework\Admin\Widget;

/**
 * Base for the playback widgets: everything driven by the range selector and
 * backed by PlaybackAnalyticsService.
 */
abstract class AnalyticsWidget extends Widget
{
    use ResolvesRange;

    protected string $group = 'listening';
    protected bool $ranged = true;

    public function __construct(protected PlaybackAnalyticsService $analytics)
    {
    }
}
