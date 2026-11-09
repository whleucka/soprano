<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "playlist_members";

    public function up(): string
    {
        return Schema::create($this->table, function (Blueprint $table) {
            // Collaborators on someone else's playlist. The owner is
            // playlists.client_id and never has a row here. Every member can
            // add and remove tracks; only the owner can rename, delete or
            // manage who is in.
            $table->id();
            $table->unsignedBigInteger("playlist_id");
            $table->unsignedBigInteger("client_id");
            $table->timestamp("created_at")->default("CURRENT_TIMESTAMP");
            $table->primaryKey("id");
            $table->unique("playlist_id,client_id");
            $table->foreignKey("playlist_id")->references("playlists", "id")->onDelete("CASCADE");
            $table->foreignKey("client_id")->references("clients", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
        return Schema::drop($this->table);
    }
};
