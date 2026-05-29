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
            $table->varchar("disc_number")->nullable();
            $table->varchar("playtime_string");
            $table->unsignedInteger("length_ms")->nullable();
            $table->varchar("bitrate");
            $table->varchar("mime_type");
            $table->text("composer")->nullable();
            $table->varchar("bpm")->nullable();
            $table->varchar("replaygain_track_gain")->nullable();
            $table->varchar("replaygain_track_peak")->nullable();
            $table->longText("lyrics")->nullable();
            $table->varchar("musicbrainz_track_id")->nullable();
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
