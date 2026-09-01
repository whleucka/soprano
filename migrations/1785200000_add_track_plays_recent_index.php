<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "track_plays";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // recentlyPlayed() groups a week of plays by track and takes
            // MAX(id) per group. Without this the derived table is a full
            // scan of every play ever recorded plus a temp table; with it
            // the range covers only the window and MAX(id) comes off the
            // index, so the grouping never leaves the index.
            //
            // Column order matters: created_at first makes the WHERE a range
            // scan. A (track_id, created_at, id) index also lets the grouping
            // skip the filesort but has to walk the whole table to do it, and
            // measured no faster — it was tried and dropped.
            $table->addIndex("idx_track_plays_created_track", ["created_at", "track_id", "id"]);
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropIndex("idx_track_plays_created_track");
        });
    }
};
