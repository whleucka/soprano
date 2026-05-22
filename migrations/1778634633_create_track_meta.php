<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "track_meta";

    public function up(): string
    {
        return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("track_id");
            $table->varchar("title");
            $table->varchar("track_number");
            $table->varchar("playtime_string");
            $table->varchar("bitrate");
            $table->varchar("mime_type");
            $table->timestamps();
            $table->primaryKey("id");
            $table->foreignKey("track_id")
                ->references("tracks", "id")
                ->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
        return Schema::drop($this->table);
    }
};
