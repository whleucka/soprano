<?php

use Echo\Framework\Database\MigrationInterface;

return new class implements MigrationInterface
{
    public function up(): string
    {
        return "INSERT INTO modules (link, title, icon, item_order, parent_id) VALUES
            (null, 'Soprano', null, 15, null)";
    }

    public function down(): string
    {
        return "DELETE FROM modules WHERE title = 'Soprano'";
    }
};
