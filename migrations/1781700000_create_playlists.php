<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "playlists";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            // 32-char random hex, used as the public route handle like the rest
            // of the app (albums/tracks/radio). Generated in PlaylistsService.
            $table->char("hash", 32);
            $table->unsignedBigInteger("client_id");
            $table->varchar("name");
            $table->timestamps();
            $table->primaryKey("id");
            $table->unique("hash");
            $table->foreignKey("client_id")->references("clients", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
