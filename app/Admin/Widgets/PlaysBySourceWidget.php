<?php

namespace App\Admin\Widgets;

/**
 * Where playback came from — album pages, stations, search, shuffle.
 *
 * Rendered as ranked bars rather than a pie: there are usually two dominant
 * sources and a long tail, and a pie chart of that is unreadable.
 */
class PlaysBySourceWidget extends AnalyticsWidget
{
    protected string $id = 'plays-by-source';
    protected string $title = 'Plays by source';
    protected string $icon = 'signpost-split';
    protected string $template = 'admin/widgets/plays-by-source.html.twig';
    protected int $width = 4;
    protected int $priority = 110;

    public function getData(): array
    {
        return [
            'sources' => $this->analytics->getPlaysBySource($this->range()),
        ];
    }
}
