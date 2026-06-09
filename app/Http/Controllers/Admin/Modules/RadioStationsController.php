<?php

namespace App\Http\Controllers\Admin\Modules;

use Echo\Framework\Admin\Schema\{FormSchemaBuilder, TableSchemaBuilder};
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;

#[Group(pathPrefix: "/radio-stations", namePrefix: "radio-stations")]
class RadioStationsController extends ModuleController
{
    protected string $tableName = "radio_stations";

    protected function defineTable(TableSchemaBuilder $builder): void
    {
        $builder->primaryKey('radio_stations.id')
                ->dateColumn('radio_stations.created_at')
                ->defaultSort('radio_stations.id', 'DESC');

        $builder->column('id', 'ID', 'radio_stations.id');
        $builder->column('cover', 'Cover', 'radio_stations.cover')
                ->formatUsing(fn($col, $val) => $this->thumbnail($val));
        $builder->column('name', 'Name', 'radio_stations.name')->searchable();
        $builder->column('country', 'Country', 'radio_stations.country')->searchable();
        $builder->column('province', 'Province', 'radio_stations.province');
        $builder->column('city', 'City', 'radio_stations.city')->searchable();
        $builder->column('created_at', 'Added', 'radio_stations.created_at');

        $builder->filter('country', 'radio_stations.country')
                ->label('Country')
                ->optionsFrom("SELECT DISTINCT country as value, country as label FROM radio_stations WHERE country <> '' ORDER BY country");

        $builder->rowAction('show');
        $builder->rowAction('edit');
        $builder->rowAction('delete');
        $builder->toolbarAction('create');
        $builder->toolbarAction('export');
        $builder->bulkAction('delete', 'Delete');
    }

    protected function defineForm(FormSchemaBuilder $builder): void
    {
        $builder->field('name', 'Name')->input()->rules(['required']);
        $builder->field('src', 'Stream URL')->input()->rules(['required', 'url']);
        $builder->field('cover', 'Cover URL')->input()->rules(['url']);
        $builder->field('country', 'Country')->input();
        $builder->field('province', 'Province')->input();
        $builder->field('city', 'City')->input();
    }

    /**
     * Stations are user-curated, but the table carries a unique NOT NULL hash
     * (mirroring the synced music tables). Derive it from the stream URL.
     */
    protected function handleStore(array $request): mixed
    {
        $request['hash'] = md5($request['src'] ?? uniqid('', true));
        return parent::handleStore($request);
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
}
