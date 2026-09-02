<?php

namespace App\Admin\Widgets;

class TopAlbumsWidget extends AnalyticsWidget
{
    protected string $id = 'top-albums';
    protected string $title = 'Top albums';
    protected string $icon = 'disc';
    protected string $template = 'admin/widgets/leaderboard.html.twig';
    protected int $width = 4;
    protected int $priority = 160;

    public function getData(): array
    {
        return [
            'rows' => $this->analytics->getTopAlbums($this->range(), 8),
            'link' => uri('albums.admin.index'),
            'link_label' => 'View all albums',
            'empty' => 'Nothing played in this window',
            'round' => false,
        ];
    }
}
