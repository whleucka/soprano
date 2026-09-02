<?php

namespace App\Admin\Widgets;

/**
 * The scoreboard for everything Soprano generates.
 *
 * Completion rate per individual station, playlist and artist radio. This is
 * the one number that says whether a generated feed is picking good tracks —
 * a station sitting at 40% is dealing music nobody wants to hear through, and
 * before this there was no way to see that without writing the SQL by hand.
 */
class SourceQualityWidget extends AnalyticsWidget
{
    protected string $id = 'source-quality';
    protected string $title = 'Source quality';
    protected string $icon = 'award';
    protected string $template = 'admin/widgets/source-quality.html.twig';
    protected int $width = 6;
    protected int $priority = 120;

    public function getData(): array
    {
        return [
            'sources' => $this->analytics->getSourceQuality($this->range()),
        ];
    }
}
