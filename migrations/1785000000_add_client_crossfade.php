<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->boolean("crossfade")->default("1");
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("crossfade");
        });
    }
};
