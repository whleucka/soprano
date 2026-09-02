<?php

namespace App\Admin\Widgets;

/**
 * The headline strip: six numbers with a sparkline and a delta each.
 *
 * Not a card — it renders bare tiles, so it deliberately skips the shared
 * card shell. Priority 10 keeps it first in its band whatever else lands there.
 */
class ListeningKpiWidget extends AnalyticsWidget
{
    protected string $id = 'listening-kpis';
    protected string $title = 'Listening';
    protected string $icon = 'headphones';
    protected string $template = 'admin/widgets/listening-kpis.html.twig';
    protected string $group = 'kpi';
    protected int $width = 12;
    protected int $priority = 10;

    public function getData(): array
    {
        $range = $this->range();

        return [
            'kpis' => $this->analytics->getKpis($range),
            'range' => $range,
        ];
    }
}
