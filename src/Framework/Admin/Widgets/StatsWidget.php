<?php

namespace Echo\Framework\Admin\Widgets;

use App\Services\Admin\DashboardService;
use App\Services\Admin\MusicStatsService;
use Echo\Framework\Admin\Widget;

class StatsWidget extends Widget
{
    protected string $id = 'stats';
    protected string $title = 'Quick Stats';
    protected string $icon = 'bar-chart';
    protected string $template = 'admin/widgets/stats.html.twig';
    protected int $width = 12;
    protected int $refreshInterval = 60;
    protected int $priority = 10;

    public function __construct(
        private DashboardService $dashboardService,
        private MusicStatsService $musicStatsService,
    ) {
    }

    public function getData(): array
    {
        $usersCount = $this->dashboardService->getUsersCount();

        $activeUsers = $this->dashboardService->getActiveUsersCount();

        $todayRequests = $this->dashboardService->getTodayRequests();

        $totalRequests = $this->dashboardService->getTotalRequests();

        $modulesCount = $this->dashboardService->getModulesCount();

        $auditData = $this->dashboardService->getAuditSummary();
        $auditCount = $auditData['today'];

        $library = $this->musicStatsService->getLibraryStats();

        return [
            'stats' => [
                [
                    'label' => 'Tracks',
                    'value' => (int)$library['tracks'],
                    'icon' => 'music-note-beamed',
                    'color' => 'primary',
                ],
                [
                    'label' => 'Artists',
                    'value' => (int)$library['artists'],
                    'icon' => 'person-circle',
                    'color' => 'info',
                ],
                [
                    'label' => 'Albums',
                    'value' => (int)$library['albums'],
                    'icon' => 'disc',
                    'color' => 'success',
                ],
                [
                    'label' => 'Radio Stations',
                    'value' => (int)$library['radio_stations'],
                    'icon' => 'broadcast',
                    'color' => 'warning',
                ],
                [
                    'label' => 'Plays',
                    'value' => (int)$library['plays'],
                    'icon' => 'play-circle',
                    'color' => 'secondary',
                ],
                [
                    'label' => 'Likes',
                    'value' => (int)$library['likes'],
                    'icon' => 'heart',
                    'color' => 'danger',
                ],
                [
                    'label' => 'Clients',
                    'value' => (int)$library['clients'],
                    'icon' => 'phone',
                    'color' => 'info',
                ],
                [
                    'label' => 'New Clients (30d)',
                    'value' => (int)$library['new_clients'],
                    'icon' => 'phone-vibrate',
                    'color' => 'success',
                ],
                [
                    'label' => 'Total Users',
                    'value' => (int)$usersCount,
                    'icon' => 'people',
                    'color' => 'primary',
                ],
                [
                    'label' => 'Active Now',
                    'value' => (int)$activeUsers,
                    'icon' => 'person-check',
                    'color' => 'success',
                ],
                [
                    'label' => "Today's Requests",
                    'value' => (int)$todayRequests,
                    'icon' => 'lightning',
                    'color' => 'warning',
                ],
                [
                    'label' => 'Total Requests',
                    'value' => (int)$totalRequests,
                    'icon' => 'graph-up',
                    'color' => 'info',
                ],
                [
                    'label' => 'Modules',
                    'value' => (int)$modulesCount,
                    'icon' => 'puzzle',
                    'color' => 'secondary',
                ],
                [
                    'label' => "Today's Changes",
                    'value' => (int)$auditCount,
                    'icon' => 'journal-text',
                    'color' => 'danger',
                ],
            ],
        ];
    }
}
