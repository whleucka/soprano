<?php

namespace App\Services\Soprano;

use App\Models\{RadioStation, RadioStationLike};

class RadioService
{
    public function getStation(string $hash): ?RadioStation
    {
        return RadioStation::where('hash', $hash)->first();
    }

    /**
     * All radio stations with a per-client `liked` flag, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStations(): array
    {
        $rows = db()->fetchAll(
            "SELECT rs.hash AS hash,
                    rs.name AS name,
                    rs.cover AS cover,
                    rs.country AS country,
                    rs.province AS province,
                    rs.city AS city,
                    rs.src AS src,
                    IFNULL((SELECT 1 FROM radio_station_likes WHERE client_id=? AND radio_station_id=rs.id), 0) AS liked
             FROM radio_stations rs
             ORDER BY rs.country IS NULL, rs.country, rs.province, rs.city, rs.name",
            [client()->id],
        );

        return array_map(fn($row) => $this->mapStationRow($row), $rows);
    }

    /**
     * Stations for the radio index, one group per country in getStations()
     * order, with the client's liked stations in a group of their own up front.
     * A liked station also stays under its country, so the country groups read
     * the same whether or not you've liked anything.
     *
     * @param array<int, array<string, mixed>> $stations
     * @return array<int, array{name: string, liked: bool, stations: array<int, array<string, mixed>>}>
     */
    public function groupStations(array $stations): array
    {
        $groups = [];
        $liked = array_values(array_filter($stations, fn($s) => $s['liked']));
        if ($liked) {
            $groups[] = ['name' => 'Liked', 'liked' => true, 'stations' => $liked];
        }

        $byCountry = [];
        foreach ($stations as $s) {
            $byCountry[$s['country'] ?: 'Elsewhere'][] = $s;
        }
        foreach ($byCountry as $country => $rows) {
            $groups[] = ['name' => (string) $country, 'liked' => false, 'stations' => $rows];
        }

        return $groups;
    }

    /**
     * Stations liked by the current client, most recently liked first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLikedStations(): array
    {
        $rows = db()->fetchAll(
            "SELECT rs.hash AS hash,
                    rs.name AS name,
                    rs.cover AS cover,
                    rs.country AS country,
                    rs.province AS province,
                    rs.city AS city,
                    rs.src AS src,
                    1 AS liked
             FROM radio_stations rs
             JOIN radio_station_likes rsl ON rsl.radio_station_id = rs.id
             WHERE rsl.client_id = ?
             ORDER BY rsl.created_at DESC",
            [client()->id],
        );

        return array_map(fn($row) => $this->mapStationRow($row), $rows);
    }

    public function isStationLiked(string $hash): bool
    {
        $station = $this->getStation($hash);
        if (!$station) {
            return false;
        }
        $like = RadioStationLike::where("radio_station_id", $station->id)
            ->andWhere("client_id", client()->id)->first();

        return (bool) $like;
    }

    public function toggleStationLike(string $hash): void
    {
        $station = $this->getStation($hash);
        if (!$station) {
            return;
        }
        $like = RadioStationLike::where("radio_station_id", $station->id)
            ->andWhere("client_id", client()->id)->first();

        if ($like) {
            $like->delete();
        } else {
            RadioStationLike::create([
                'radio_station_id' => $station->id,
                'client_id'        => client()->id,
            ]);
        }
    }

    /**
     * Normalise a raw station row for the views: location string + boolean like.
     * `has_cover` is false when the cover is the default art, which the cards
     * show full-bleed rather than letterboxed like a station logo.
     */
    private function mapStationRow(array $row): array
    {
        $location = array_filter([$row['city'] ?? null, $row['province'] ?? null]);

        return [
            'hash'     => $row['hash'],
            'name'     => $row['name'],
            'cover'    => $row['cover'] ?: '/images/no-album-art.png',
            'has_cover' => (bool) $row['cover'],
            'country'  => $row['country'],
            'province' => $row['province'],
            'city'     => $row['city'],
            'location' => implode(', ', $location),
            'src'      => $row['src'],
            'liked'    => (bool) $row['liked'],
        ];
    }
}
