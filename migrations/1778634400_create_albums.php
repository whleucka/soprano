<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "albums";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->char("hash", 32);
            $table->varchar("title");
            $table->varchar("cover")->nullable();
            $table->varchar("genre");
            $table->varchar("year");
            $table->timestamps();
            $table->unique("hash");
            $table->primaryKey("id");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
