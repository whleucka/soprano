<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "track_likes";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("track_id");
            $table->unsignedBigInteger("client_id");
            $table->timestamp("created_at")->default("CURRENT_TIMESTAMP");
            $table->primaryKey("id");
            $table->unique("track_id,client_id");
            $table->foreignKey("track_id")->references("tracks", "id")->onDelete("CASCADE");
            $table->foreignKey("client_id")->references("clients", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
