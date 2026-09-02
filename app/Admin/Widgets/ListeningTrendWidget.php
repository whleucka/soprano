<?php

namespace App\Admin\Widgets;

/**
 * Listening time and plays over the window.
 *
 * Two series on two axes: minutes listened as an area, plays as a line. They
 * move together most of the time, and the interesting days are the ones where
 * they don't — lots of plays and little listening time means skipping.
 */
class ListeningTrendWidget extends AnalyticsWidget
{
    protected string $id = 'listening-trend';
    protected string $title = 'Listening over time';
    protected string $icon = 'graph-up';
    protected string $template = 'admin/widgets/listening-trend.html.twig';
    protected int $width = 8;
    protected int $priority = 100;

    public function getData(): array
    {
        $series = $this->analytics->getSeries($this->range());

        return [
            'empty' => array_sum(array_column($series, 'plays')) === 0,
            'chart' => [
                'type' => 'line',
                'labels' => array_column($series, 'label'),
                'datasets' => [
                    [
                        'label' => 'Minutes listened',
                        'data' => array_map(
                            fn(array $b) => round($b['ms'] / 60000, 1),
                            $series
                        ),
                        'role' => 'accent',
                        'fill' => true,
                        'axis' => 'y',
                    ],
                    [
                        'label' => 'Plays',
                        'data' => array_column($series, 'plays'),
                        'role' => 'info',
                        'fill' => false,
                        'axis' => 'y1',
                    ],
                ],
                'axes' => [
                    'y' => ['title' => 'Minutes'],
                    'y1' => ['title' => 'Plays', 'position' => 'right'],
                ],
            ],
        ];
    }
}
