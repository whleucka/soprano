<?php

namespace App\Admin\Widgets;

/**
 * Library size over time, plus what each bucket added.
 *
 * The running total is the line worth watching; the bars make a finished
 * library sync visible without opening the Tracks module.
 */
class LibraryGrowthWidget extends AnalyticsWidget
{
    protected string $id = 'library-growth';
    protected string $title = 'Library growth';
    protected string $icon = 'collection';
    protected string $template = 'admin/widgets/library-growth.html.twig';
    protected int $width = 6;
    protected int $priority = 170;

    public function getData(): array
    {
        $growth = $this->analytics->getLibraryGrowth($this->range());

        return [
            'totals' => $this->analytics->getLibraryTotals(),
            'added' => array_sum(array_column($growth, 'added')),
            'chart' => [
                'type' => 'bar',
                'labels' => array_column($growth, 'label'),
                'datasets' => [
                    [
                        'label' => 'Tracks added',
                        'data' => array_column($growth, 'added'),
                        'role' => 'accent',
                        'axis' => 'y',
                    ],
                    [
                        'label' => 'Library size',
                        'data' => array_column($growth, 'total'),
                        'role' => 'muted',
                        'type' => 'line',
                        'fill' => false,
                        'axis' => 'y1',
                    ],
                ],
                'axes' => [
                    'y' => ['title' => 'Added'],
                    'y1' => ['title' => 'Total', 'position' => 'right'],
                ],
            ],
        ];
    }
}
