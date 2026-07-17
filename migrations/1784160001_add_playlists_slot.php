<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "playlists";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Generator slot ('heavy-rotation', 'morning-mix', …) for the
            // nightly generated mixes. NULL = a normal user-created playlist.
            // At most one playlist per (client, slot), enforced by the job.
            $table->varchar("slot", 32)->nullable();
            $table->addIndex("idx_playlists_client_slot", ["client_id", "slot"]);
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            $table->dropIndex("idx_playlists_client_slot");
            $table->dropColumn("slot");
        });
    }
};
