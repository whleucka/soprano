<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "podcast_progress";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("client_id");
            // ListenNotes episode id (32-char hex) — episodes live remotely, so
            // we snapshot enough metadata here to render "Continue Listening"
            // without an API round-trip.
            $table->char("episode_id", 32);
            $table->char("podcast_hash", 32);
            $table->varchar("podcast_title");
            $table->varchar("episode_title");
            $table->varchar("image")->nullable();
            $table->unsignedInteger("duration_sec")->nullable();
            $table->unsignedInteger("position_ms")->default("0");
            $table->timestamps();
            $table->primaryKey("id");
            $table->unique("client_id,episode_id");
            $table->foreignKey("client_id")->references("clients", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
