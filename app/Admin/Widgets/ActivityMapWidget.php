<?php

namespace App\Admin\Widgets;

use App\Services\Admin\OpsSummaryService;
use Echo\Framework\Admin\Widget;

/**
 * Unique visitors by country.
 *
 * Was a framework widget with its own `today|7d|30d|year` selector, which meant
 * two range vocabularies on one page fighting over the same `?range=`. Now it
 * follows the page range like everything else and has no controls of its own.
 */
class ActivityMapWidget extends Widget
{
    use ResolvesRange;

    protected string $id = 'activity-map';
    protected string $title = 'Visitors by country';
    protected string $icon = 'globe-americas';
    protected string $template = 'admin/widgets/activity-map.html.twig';
    protected string $group = 'ops';
    protected bool $ranged = true;
    protected int $width = 4;
    protected int $refreshInterval = 3600;
    protected int $priority = 320;

    public function __construct(private OpsSummaryService $ops)
    {
    }

    public function getData(): array
    {
        return $this->ops->getCountryActivity($this->range());
    }
}
