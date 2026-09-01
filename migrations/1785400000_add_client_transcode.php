<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Default on: transcoding is what makes lossless sources play at
            // all in most browsers, so opting out is the deliberate choice.
            $table->boolean("transcode")->default("1");
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("transcode");
        });
    }
};
