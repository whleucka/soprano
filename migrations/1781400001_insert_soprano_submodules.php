<?php

use Echo\Framework\Database\MigrationInterface;

return new class implements MigrationInterface
{
    public function up(): string
    {
        // Resolve the parent id via a derived table so MySQL allows selecting
        // from `modules` while inserting into it (avoids error 1093).
        return "INSERT INTO modules (link, title, icon, item_order, parent_id)
            SELECT 'tracks', 'Tracks', 'music-note-beamed', 10, m.id FROM (SELECT id FROM modules WHERE title = 'Soprano') m
            UNION ALL SELECT 'artists', 'Artists', 'person-circle', 20, m.id FROM (SELECT id FROM modules WHERE title = 'Soprano') m
            UNION ALL SELECT 'albums', 'Albums', 'disc', 30, m.id FROM (SELECT id FROM modules WHERE title = 'Soprano') m
            UNION ALL SELECT 'radio-stations', 'Radio Stations', 'broadcast', 40, m.id FROM (SELECT id FROM modules WHERE title = 'Soprano') m";
    }

    public function down(): string
    {
        return "DELETE FROM modules WHERE link IN ('tracks', 'artists', 'albums', 'radio-stations')";
    }
};
