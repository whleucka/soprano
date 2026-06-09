<?php

namespace App\Admin\Widgets;

use App\Services\Admin\MusicStatsService;
use Echo\Framework\Admin\Widget;

class TopArtistsWidget extends Widget
{
    protected string $id = 'soprano-top-artists';
    protected string $title = 'Top Artists';
    protected string $icon = 'person-lines-fill';
    protected string $template = 'admin/widgets/top-artists.html.twig';
    protected int $width = 6;
    protected int $refreshInterval = 120;
    protected int $priority = 220;

    public function __construct(private MusicStatsService $service)
    {
    }

    public function getData(): array
    {
        return [
            'artists' => $this->service->getTopArtists(5),
        ];
    }
}
