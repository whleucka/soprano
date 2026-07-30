<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Opt-in crossfade: overlap the end of one track with the start of
            // the next (WebAudio gain ramp on the client). Off by default.
            $table->boolean("crossfade")->default("0");
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("crossfade");
        });
    }
};
