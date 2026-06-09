<?php

namespace App\Admin\Widgets;

use App\Services\Admin\MusicStatsService;
use Echo\Framework\Admin\Widget;

class TopTracksWidget extends Widget
{
    protected string $id = 'soprano-top-tracks';
    protected string $title = 'Top Tracks';
    protected string $icon = 'music-note-list';
    protected string $template = 'admin/widgets/top-tracks.html.twig';
    protected int $width = 6;
    protected int $refreshInterval = 120;
    protected int $priority = 210;

    public function __construct(private MusicStatsService $service)
    {
    }

    public function getData(): array
    {
        return [
            'tracks' => $this->service->getTopTracks(5),
        ];
    }
}
