<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "track_plays";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // How far playback got when the play was closed out. NULL = never
            // reported (rows from before this column, or the tab died without
            // a final report).
            $table->unsignedInteger("ms_played")->nullable();
            // 1 = abandoned early, 0 = completed (natural end or >=80%
            // listened), NULL = unknown.
            $table->boolean("skipped")->nullable();
            // Where the play started from: album, artist, search, liked,
            // random, playlist:<hash> — lets us score generated playlists.
            $table->varchar("source", 64)->nullable();
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("ms_played");
            $table->dropColumn("skipped");
            $table->dropColumn("source");
        });
    }
};
