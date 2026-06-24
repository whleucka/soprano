<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "playlist_tracks";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("playlist_id");
            $table->unsignedBigInteger("track_id");
            // `id` doubles as insertion order — rows are listed ORDER BY id ASC.
            $table->timestamp("created_at")->default("CURRENT_TIMESTAMP");
            $table->primaryKey("id");
            $table->unique("playlist_id,track_id");
            $table->foreignKey("playlist_id")->references("playlists", "id")->onDelete("CASCADE");
            $table->foreignKey("track_id")->references("tracks", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
