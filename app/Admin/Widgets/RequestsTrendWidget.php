<?php

namespace App\Admin\Widgets;

use App\Services\Admin\OpsSummaryService;
use Echo\Framework\Admin\Widget;

/**
 * HTTP traffic over the selected window, successes and errors stacked.
 *
 * Replaces four separate charts — today, this week, this month, year to date —
 * which plotted the same metric over four hard-coded windows and were the
 * clearest symptom of a dashboard with no shared notion of a time range.
 */
class RequestsTrendWidget extends Widget
{
    use ResolvesRange;

    protected string $id = 'requests-trend';
    protected string $title = 'Requests';
    protected string $icon = 'arrow-left-right';
    protected string $template = 'admin/widgets/requests-trend.html.twig';
    protected string $group = 'ops';
    protected bool $ranged = true;
    protected int $width = 8;
    protected int $priority = 310;

    public function __construct(private OpsSummaryService $ops)
    {
    }

    public function getData(): array
    {
        return ['chart' => $this->ops->getRequestsChart($this->range())];
    }
}
