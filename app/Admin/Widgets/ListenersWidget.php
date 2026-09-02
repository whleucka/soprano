<?php

namespace App\Admin\Widgets;

/**
 * Per-client listening.
 *
 * Soprano has a handful of clients, not a user base, so this is a table — at
 * that size the individual rows are the interesting part and a chart would
 * just be five bars with a legend.
 */
class ListenersWidget extends AnalyticsWidget
{
    protected string $id = 'listeners';
    protected string $title = 'Listeners';
    protected string $icon = 'people';
    protected string $template = 'admin/widgets/listeners.html.twig';
    protected int $width = 6;
    protected int $priority = 180;

    public function getData(): array
    {
        return [
            'listeners' => $this->analytics->getListeners($this->range()),
        ];
    }
}
