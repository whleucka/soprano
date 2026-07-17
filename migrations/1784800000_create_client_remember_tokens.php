<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "client_remember_tokens";

    public function up(): string
    {
        return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("client_id");
            // Split-token scheme: selector is looked up, validator is only
            // stored hashed so a DB leak can't forge cookies.
            $table->char("selector", 24);
            $table->char("validator_hash", 64);
            // Unix timestamp — avoids db container TZ skew
            $table->unsignedBigInteger("expires_at");
            $table->timestamps();
            $table->primaryKey("id");
            $table->unique("selector");
            $table->foreignKey("client_id")->references("clients", "id")->onDelete("CASCADE");
        });
    }

    public function down(): string
    {
        return Schema::drop($this->table);
    }
};
