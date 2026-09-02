<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "clients";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Deliberately absent from every form and settings payload: the
            // only way to grant it is an UPDATE against the database, so a
            // compromised client session can't escalate itself.
            $table->boolean("is_admin")->default("0");
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropColumn("is_admin");
        });
    }
};
