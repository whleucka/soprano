<?php

namespace App\Admin\Widgets;

class TopArtistsWidget extends AnalyticsWidget
{
    protected string $id = 'top-artists';
    protected string $title = 'Top artists';
    protected string $icon = 'person-lines-fill';
    protected string $template = 'admin/widgets/leaderboard.html.twig';
    protected int $width = 4;
    protected int $priority = 150;

    public function getData(): array
    {
        return [
            'rows' => $this->analytics->getTopArtists($this->range(), 8),
            'link' => uri('artists.admin.index'),
            'link_label' => 'View all artists',
            'empty' => 'Nothing played in this window',
            // Artist images are portraits, not sleeves — circles read better.
            'round' => true,
        ];
    }
}
