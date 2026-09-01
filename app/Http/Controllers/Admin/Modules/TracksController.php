<?php

namespace App\Http\Controllers\Admin\Modules;

use Echo\Framework\Admin\Schema\{FormSchemaBuilder, TableSchemaBuilder};
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;

#[Group(pathPrefix: "/tracks", namePrefix: "tracks")]
class TracksController extends ModuleController
{
    protected string $tableName = "tracks";

    protected function defineTable(TableSchemaBuilder $builder): void
    {
        $builder->primaryKey('tracks.id')
                ->join('LEFT JOIN track_meta ON track_meta.track_id = tracks.id')
                ->join('LEFT JOIN artists ON artists.id = tracks.track_artist_id')
                ->join('LEFT JOIN albums ON albums.id = tracks.album_id')
                ->dateColumn('tracks.created_at')
                ->defaultSort('tracks.id', 'DESC');

        $builder->column('id', 'ID', 'tracks.id');
        $builder->column('title', 'Title', 'track_meta.title')->searchable();
        $builder->column('artist', 'Artist', 'artists.name')->searchable();
        $builder->column('album', 'Album', 'albums.title')->searchable();
        $builder->column('duration', 'Duration', 'track_meta.playtime_string');
        $builder->column('plays', 'Plays', '(SELECT COUNT(*) FROM track_plays WHERE track_plays.track_id = tracks.id)');
        $builder->column('likes', 'Likes', '(SELECT COUNT(*) FROM track_likes WHERE track_likes.track_id = tracks.id)');
        $builder->column('open', 'Open', 'tracks.hash')
                ->formatUsing(fn($col, $val) => $this->frontendLink($val, 'track.index'));
        $builder->column('created_at', 'Added', 'tracks.created_at');

        $builder->filter('artist', 'tracks.track_artist_id')
                ->label('Artist')
                ->optionsFrom("SELECT id as value, name as label FROM artists ORDER BY name");
        $builder->filter('genre', 'albums.genre')
                ->label('Genre')
                ->optionsFrom("SELECT DISTINCT genre as value, genre as label FROM albums WHERE genre <> '' ORDER BY genre");

        $builder->rowAction('show');
        $builder->rowAction('delete');
        $builder->toolbarAction('export');
        $builder->bulkAction('delete', 'Delete');
    }

    protected function defineForm(FormSchemaBuilder $builder): void
    {
        // Read-only view form (tracks are filesystem-synced; no create/edit).
        $builder->field('title', 'Title', "(SELECT title FROM track_meta WHERE track_meta.track_id = tracks.id)")->input();
        $builder->field('artist', 'Artist', "(SELECT name FROM artists WHERE artists.id = tracks.track_artist_id)")->input();
        $builder->field('album', 'Album', "(SELECT title FROM albums WHERE albums.id = tracks.album_id)")->input();
        $builder->field('duration', 'Duration', "(SELECT playtime_string FROM track_meta WHERE track_meta.track_id = tracks.id)")->input();
        $builder->field('bitrate', 'Bitrate', "(SELECT bitrate FROM track_meta WHERE track_meta.track_id = tracks.id)")->input();
        $builder->field('plays', 'Plays', "(SELECT COUNT(*) FROM track_plays WHERE track_plays.track_id = tracks.id)")->input();
        $builder->field('likes', 'Likes', "(SELECT COUNT(*) FROM track_likes WHERE track_likes.track_id = tracks.id)")->input();
        $builder->field('filename', 'Filename')->input();
        $builder->field('pathname', 'Path')->textarea();
        $builder->field('created_at', 'Added')->input();
    }

    /**
     * Render a button linking to the frontend page for this record.
     *
     * Built against the app's public base rather than a relative uri(): the
     * link opens in a new tab, and an absolute URL is what belongs there.
     */
    private function frontendLink(?string $hash, string $route): string
    {
        if (!$hash) {
            return '<span class="text-muted">—</span>';
        }
        $href = rtrim(config('app.url') ?? '', '/') . uri($route, $hash);
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="View on site"><i class="bi bi-box-arrow-up-right"></i></a>',
            htmlspecialchars($href, ENT_QUOTES)
        );
    }
}
