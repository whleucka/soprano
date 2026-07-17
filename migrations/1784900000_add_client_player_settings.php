<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Per-client player defaults, seeded into the session queue at
            // sign-in (and remember-me restore) so a fresh listening session
            // starts the way the client prefers.
            $table->boolean("default_shuffle")->default("0");
            // 'off' | 'all' | 'one' — mirrors PlaylistService::REPEAT_MODES.
            $table->varchar("default_repeat", 8)->default("'off'");
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("default_shuffle");
            $table->dropColumn("default_repeat");
        });
    }
};
