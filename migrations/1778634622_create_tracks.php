<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "tracks";

    public function up(): string
    {
        return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->char("hash", 32);
            $table->unsignedBigInteger("artist_id");
            $table->unsignedBigInteger("track_artist_id");
            $table->unsignedBigInteger("album_id");
            $table->text("filename");
            $table->text("pathname");
            $table->timestamp("created_at")->default("CURRENT_TIMESTAMP");
            $table->unique("hash");
            $table->primaryKey("id");
            $table->foreignKey("artist_id")
                ->references("artists", "id");
            $table->foreignKey("track_artist_id")
                ->references("artists", "id");
            $table->foreignKey("album_id")
                ->references("albums", "id");
        });
    }

    public function down(): string
    {
        return Schema::drop($this->table);
    }
};
