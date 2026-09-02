<?php

namespace App\Admin\Widgets;

/**
 * Plays by weekday and hour.
 *
 * Replaces the framework's request heatmap, which lit up whenever a phone woke
 * up and polled an endpoint. This one only lights up when something played.
 */
class ListeningClockWidget extends AnalyticsWidget
{
    protected string $id = 'listening-clock';
    protected string $title = 'Listening clock';
    protected string $icon = 'clock-history';
    protected string $template = 'admin/widgets/listening-clock.html.twig';
    protected int $width = 6;
    protected int $priority = 130;

    public function getData(): array
    {
        return $this->analytics->getListeningClock($this->range());
    }
}
