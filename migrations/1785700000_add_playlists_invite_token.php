<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "playlists";

    public function up(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // The collaborator invite link, /invite/{token}. NULL = no live
            // link. The owner can mint a new one (which kills the old link)
            // or clear it; people who already joined keep their membership
            // either way. Kept in the clear because the owner's
            // collaborators modal has to show the link again later.
            $table->char("invite_token", 32)->nullable();
            // A plain index: Blueprint's unique() only works in create mode,
            // and 128 random bits don't collide.
            $table->addIndex("idx_playlists_invite_token", ["invite_token"]);
        });
    }

    public function down(): string
    {
        return Schema::alter($this->table, function (Blueprint $table) {
            // Dropping the column takes its single-column index with it.
            $table->dropColumn("invite_token");
        });
    }
};
