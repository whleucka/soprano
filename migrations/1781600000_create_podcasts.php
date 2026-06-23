<?php

use Echo\Framework\Database\{Schema, Blueprint, MigrationInterface};

return new class implements MigrationInterface
{
    private string $table = "podcasts";

    public function up(): string
    {
         return Schema::create($this->table, function (Blueprint $table) {
            $table->id();
            // `hash` is the ListenNotes podcast id (32-char hex) — used as the
            // public route handle so URLs match the rest of the app.
            $table->char("hash", 32);
            $table->varchar("title");
            $table->varchar("publisher")->nullable();
            $table->text("description")->nullable();
            $table->varchar("image")->nullable();
            $table->varchar("thumbnail")->nullable();
            $table->integer("total_episodes")->nullable();
            $table->varchar("listennotes_url")->nullable();
            $table->timestamps();
            $table->primaryKey("id");
            $table->unique("hash");
        });
    }

    public function down(): string
    {
         return Schema::drop($this->table);
    }
};
