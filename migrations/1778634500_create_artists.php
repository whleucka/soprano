<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "artists";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->char("hash", 32);
            $table->varchar("name");
            $table->varchar("musicbrainz_artist_id")->nullable();
            $table->text("image")->nullable();
            $table->timestamp("image_checked_at")->nullable();
            $table->timestamps();
            $table->unique("hash");
            $table->primaryKey("id");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
