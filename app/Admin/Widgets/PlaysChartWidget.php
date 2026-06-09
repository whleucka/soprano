<?php

namespace App\Admin\Widgets;

use App\Services\Admin\MusicStatsService;
use Echo\Framework\Admin\Widget;

class PlaysChartWidget extends Widget
{
    protected string $id = 'soprano-plays-chart';
    protected string $title = 'Plays This Week';
    protected string $icon = 'graph-up-arrow';
    protected string $template = 'admin/widgets/plays-chart.html.twig';
    protected int $width = 6;
    protected int $refreshInterval = 0;
    protected int $priority = 240;

    public function __construct(private MusicStatsService $service)
    {
    }

    public function getData(): array
    {
        return $this->service->getPlaysChart();
    }
}
