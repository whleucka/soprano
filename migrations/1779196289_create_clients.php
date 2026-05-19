<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->uuid("uuid")->default("(UUID())");
            $table->unsignedBigInteger("avatar")->nullable();
            $table->varchar("username");
            $table->binary("password", 96);
            $table->timestamps();
            $table->unique("username");
            $table->primaryKey("id");
            $table->foreignKey("avatar")->references("file_info", "id")->onDelete("SET NULL");
        });
    }

    public function down(): string
    {
        return Schema::drop($this->table);
    }
};
