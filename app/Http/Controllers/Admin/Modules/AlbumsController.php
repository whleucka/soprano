<?php

namespace App\Http\Controllers\Admin\Modules;

use Echo\Framework\Admin\Schema\{FormSchemaBuilder, TableSchemaBuilder};
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;

#[Group(pathPrefix: "/albums", namePrefix: "albums")]
class AlbumsController extends ModuleController
{
    protected string $tableName = "albums";

    protected function defineTable(TableSchemaBuilder $builder): void
    {
        $builder->primaryKey('albums.id')
                ->join('LEFT JOIN artists ON artists.id = albums.artist_id')
                ->dateColumn('albums.created_at')
                ->defaultSort('albums.id', 'DESC');

        $builder->column('id', 'ID', 'albums.id');
        $builder->column('cover', 'Cover', 'albums.cover')
                ->formatUsing(fn($col, $val) => $this->thumbnail($val));
        $builder->column('title', 'Title', 'albums.title')->searchable();
        $builder->column('artist', 'Artist', 'artists.name')->searchable();
        $builder->column('genre', 'Genre', 'albums.genre');
        $builder->column('year', 'Year', 'albums.year');
        $builder->column('tracks', 'Tracks', '(SELECT COUNT(*) FROM tracks WHERE tracks.album_id = albums.id)');
        $builder->column('created_at', 'Added', 'albums.created_at');
        $builder->column('open', 'Open', 'albums.hash')
                ->formatUsing(fn($col, $val) => $this->frontendLink($val, 'album.index'));

        $builder->filter('genre', 'albums.genre')
                ->label('Genre')
                ->optionsFrom("SELECT DISTINCT genre as value, genre as label FROM albums WHERE genre <> '' ORDER BY genre");
        $builder->filter('year', 'albums.year')
                ->label('Year')
                ->optionsFrom("SELECT DISTINCT year as value, year as label FROM albums WHERE year <> '' ORDER BY year DESC");

        $builder->rowAction('show');
        $builder->rowAction('delete');
        $builder->toolbarAction('export');
        $builder->bulkAction('delete', 'Delete');
    }

    protected function defineForm(FormSchemaBuilder $builder): void
    {
        // Read-only view form (albums are filesystem-synced; no create/edit).
        $builder->field('cover', 'Cover')->input();
        $builder->field('title', 'Title')->input();
        $builder->field('artist', 'Artist', "(SELECT name FROM artists WHERE artists.id = albums.artist_id)")->input();
        $builder->field('genre', 'Genre')->input();
        $builder->field('year', 'Year')->input();
        $builder->field('tracks', 'Tracks', "(SELECT COUNT(*) FROM tracks WHERE tracks.album_id = albums.id)")->input();
        $builder->field('musicbrainz_album_id', 'MusicBrainz ID')->input();
        $builder->field('created_at', 'Added')->input();
    }

    private function thumbnail(?string $src): string
    {
        if (!$src) {
            return '<span class="text-muted">—</span>';
        }
        return sprintf(
            '<img src="%s" alt="" loading="lazy" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">',
            htmlspecialchars($src, ENT_QUOTES)
        );
    }

    /**
     * Render a button linking to the frontend page for this record.
     *
     * The admin runs on the `admin` subdomain while the player routes live on
     * the main host, so the URL is built against the app's public base.
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
