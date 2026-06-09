<?php

namespace App\Admin\Widgets;

use App\Services\Admin\MusicStatsService;
use Echo\Framework\Admin\Widget;

class RecentlyAddedWidget extends Widget
{
    protected string $id = 'soprano-recently-added';
    protected string $title = 'Recently Added';
    protected string $icon = 'clock-history';
    protected string $template = 'admin/widgets/recently-added.html.twig';
    protected int $width = 6;
    protected int $refreshInterval = 120;
    protected int $priority = 230;

    public function __construct(private MusicStatsService $service)
    {
    }

    public function getData(): array
    {
        return [
            'tracks' => $this->service->getRecentlyAdded(8),
        ];
    }
}
