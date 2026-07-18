<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "track_features";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("track_id");
            // Signal-level Essentia features (no classifier models). All
            // nullable: a row with NULL features + error records a failed
            // attempt so the backfill doesn't retry it every run.
            $table->float("bpm", 6, 2)->nullable();
            $table->float("danceability", 6, 3)->nullable();
            $table->float("energy", 12, 8)->nullable();
            $table->float("avg_loudness_db", 6, 2)->nullable();
            $table->float("dyn_complexity", 6, 3)->nullable();
            $table->varchar("key_root", 3)->nullable();
            $table->varchar("key_scale", 8)->nullable();
            $table->float("key_strength", 5, 3)->nullable();
            $table->float("zcr", 7, 5)->nullable();
            $table->varchar("extractor", 32);
            $table->varchar("error")->nullable();
            $table->timestamps();
            $table->primaryKey("id");
            $table->unique("track_id");
            $table->foreignKey("track_id")->references("tracks", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
