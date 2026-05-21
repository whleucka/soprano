<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "radio_stations";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->uuid("uuid")->default("(UUID())");
            $table->unsignedBiginteger("cover")->nullable();
            $table->varchar("name");
            $table->varchar("country")->nullable();
            $table->varchar("province")->nullable();
            $table->varchar("city")->nullable();
            $table->varchar("src");
            $table->timestamps();
            $table->primaryKey("id");
            $table->foreignKey("cover")->references("file_info", "id")->onDelete("SET NULL");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
