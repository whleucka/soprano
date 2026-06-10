<?php

namespace App\Http\Controllers\Admin\Modules;

use Echo\Framework\Admin\Schema\{FormSchemaBuilder, TableSchemaBuilder};
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;

#[Group(pathPrefix: "/artists", namePrefix: "artists")]
class ArtistsController extends ModuleController
{
    protected string $tableName = "artists";

    protected function defineTable(TableSchemaBuilder $builder): void
    {
        $builder->primaryKey('artists.id')
                ->dateColumn('artists.created_at')
                ->defaultSort('artists.id', 'DESC');

        $builder->column('id', 'ID', 'artists.id');
        $builder->column('image', 'Image', 'artists.image')
                ->formatUsing(fn($col, $val) => $this->thumbnail($val));
        $builder->column('name', 'Name', 'artists.name')->searchable();
        $builder->column('tracks', 'Tracks', '(SELECT COUNT(*) FROM tracks WHERE tracks.artist_id = artists.id)');
        $builder->column('albums', 'Albums', '(SELECT COUNT(*) FROM albums WHERE albums.artist_id = artists.id)');
        $builder->column('open', 'Open', 'artists.hash')
                ->formatUsing(fn($col, $val) => $this->frontendLink($val, 'artist.index'));
        $builder->column('created_at', 'Added', 'artists.created_at');

        $builder->rowAction('show');
        $builder->rowAction('delete');
        $builder->toolbarAction('export');
        $builder->bulkAction('delete', 'Delete');
    }

    protected function defineForm(FormSchemaBuilder $builder): void
    {
        // Read-only view form (artists are filesystem-synced; no create/edit).
        $builder->field('name', 'Name')->input();
        $builder->field('image', 'Image')->input();
        $builder->field('tracks', 'Tracks', "(SELECT COUNT(*) FROM tracks WHERE tracks.artist_id = artists.id)")->input();
        $builder->field('albums', 'Albums', "(SELECT COUNT(*) FROM albums WHERE albums.artist_id = artists.id)")->input();
        $builder->field('musicbrainz_artist_id', 'MusicBrainz ID')->input();
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
