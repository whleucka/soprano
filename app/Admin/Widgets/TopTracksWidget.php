<?php

namespace App\Admin\Widgets;

/**
 * Most-played tracks, with cover art and a completion rate.
 *
 * The play count on its own was misleading: a track at the top of the list
 * with a 30% completion rate isn't a favourite, it's something that keeps
 * getting dealt into mixes and skipped. Both numbers or neither.
 */
class TopTracksWidget extends AnalyticsWidget
{
    protected string $id = 'top-tracks';
    protected string $title = 'Top tracks';
    protected string $icon = 'music-note-list';
    protected string $template = 'admin/widgets/leaderboard.html.twig';
    protected int $width = 4;
    protected int $priority = 140;

    public function getData(): array
    {
        return [
            'rows' => $this->analytics->getTopTracks($this->range(), 8),
            'link' => uri('tracks.admin.index'),
            'link_label' => 'View all tracks',
            'empty' => 'Nothing played in this window',
            'round' => false,
        ];
    }
}
