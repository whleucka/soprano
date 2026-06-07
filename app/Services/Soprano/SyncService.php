<?php

namespace App\Services\Soprano;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Track;
use App\Models\TrackMeta;
use FilesystemIterator;
use Generator;
use JamesHeinrich\GetID3\GetID3;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SyncService
{
    private const ALLOWED_EXTENSIONS = [
        'mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'wav', 'wma', 'webm',
    ];

    private const UNKNOWN_ARTIST = 'Unknown Artist';
    private const UNKNOWN_ALBUM  = 'Unknown Album';
    private const UNKNOWN_GENRE  = 'Unknown';
    private const UNKNOWN_YEAR   = '';
    private const VARIOUS_ARTISTS = 'Various Artists';

    private GetID3 $analyzer;

    /** @var array<string,int> hash => artist_id */
    private array $artistsCache = [];

    /** @var array<string,int> hash => album_id */
    private array $albumsCache = [];

    private string $coversPath;
    private string $publicCovers;

    public function __construct(
        private CoverArtService $coverArt,
    ) {
        $this->analyzer = new GetID3();
        $this->analyzer->option_md5_data        = false;
        $this->analyzer->option_md5_data_source = false;
        $this->analyzer->option_sha1_data       = false;
        $this->analyzer->encoding               = 'UTF-8';

        $this->coversPath   = (string) config('soprano.covers_path');
        $this->publicCovers = (string) config('soprano.public_covers');
    }

    public function sync(string $path): object
    {
        $scanned  = 0;
        $inserted = 0;
        $skipped  = 0;
        $failed   = 0;
        $removed  = 0;
        $success  = true;
        $error    = null;

        try {
            $this->ensureCoversDir();
            $existing = $this->loadExistingHashes();
            $seen     = [];

            foreach ($this->iterateMediaFiles($path) as $file) {
                $scanned++;
                $pathname = $file->getPathname();
                $trackHash = md5($pathname);
                $seen[$trackHash] = true;

                if (isset($existing[$trackHash])) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->ingestFile($file, $trackHash);
                    $existing[$trackHash] = true;
                    $inserted++;
                } catch (\Throwable $e) {
                    $failed++;
                    error_log(sprintf(
                        '[soprano sync] ingest failed for %s: %s',
                        $pathname,
                        $e->getMessage(),
                    ));
                }
            }

            // Only prune when the scan actually saw files — an unmounted or
            // empty library path must not be mistaken for "everything deleted".
            if ($scanned > 0) {
                $removed = $this->removeOrphans($existing, $seen);
            }
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object) [
            'success'  => $success,
            'scanned'  => $scanned,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'removed'  => $removed,
            'error'    => $error,
        ];
    }

    /**
     * Delete tracks whose files no longer exist on disk (hashes in the DB
     * that the scan did not see). track_meta and track_plays cascade.
     *
     * @param array<string,bool> $existing hashes loaded from the DB (plus this run's inserts)
     * @param array<string,bool> $seen     hashes encountered during the scan
     */
    private function removeOrphans(array $existing, array $seen): int
    {
        $orphans = array_keys(array_diff_key($existing, $seen));
        if (!$orphans) {
            return 0;
        }

        $removed = 0;
        foreach (array_chunk($orphans, 500) as $chunk) {
            foreach (Track::whereIn('hash', $chunk)->get() as $track) {
                if ($track->delete()) {
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->pruneEmptyAlbumsAndArtists();
        }

        return $removed;
    }

    /**
     * Remove albums left with no tracks, then artists left with no tracks
     * and no albums.
     */
    private function pruneEmptyAlbumsAndArtists(): void
    {
        $db = db();
        $db->execute("DELETE FROM albums WHERE id NOT IN (SELECT DISTINCT album_id FROM tracks)");
        $db->execute(
            "DELETE FROM artists WHERE id NOT IN (SELECT DISTINCT artist_id FROM tracks)
             AND id NOT IN (SELECT DISTINCT artist_id FROM albums)"
        );
    }

    private function ingestFile(SplFileInfo $file, string $trackHash): void
    {
        $pathname = $file->getPathname();
        $info     = $this->analyze($pathname);
        $tags     = $this->extractTags($info, $file);

        $artistId = $this->resolveArtist(
            $tags['album_artist'],
            $tags['musicbrainz_artist_id'],
        );
        $albumId  = $this->resolveAlbum(
            $artistId,
            $tags['album_artist'],
            $tags['album'],
            $tags['genre'],
            $tags['year'],
            $tags['replaygain_album_gain'],
            $tags['replaygain_album_peak'],
            $tags['musicbrainz_album_id'],
            $info,
        );

        $track = Track::create([
            'hash'      => $trackHash,
            'artist_id' => $artistId,
            'album_id'  => $albumId,
            'filename'  => $file->getFilename(),
            'pathname'  => $pathname,
        ]);

        if (!$track instanceof Track) {
            throw new \RuntimeException("Failed to insert track row for {$pathname}");
        }

        TrackMeta::create([
            'track_id'              => (int) $track->id,
            'title'                 => $tags['title'],
            'track_number'          => $tags['track_number'],
            'disc_number'           => $tags['disc_number'] ?: null,
            'playtime_string'       => $tags['playtime_string'],
            'length_ms'             => $tags['length_ms'],
            'bitrate'               => $tags['bitrate'],
            'mime_type'             => $tags['mime_type'],
            'composer'              => $tags['composer'] ?: null,
            'bpm'                   => $tags['bpm'] ?: null,
            'replaygain_track_gain' => $tags['replaygain_track_gain'],
            'replaygain_track_peak' => $tags['replaygain_track_peak'],
            'lyrics'                => $tags['lyrics'] ?: null,
            'musicbrainz_track_id'  => $tags['musicbrainz_track_id'] ?: null,
        ]);
    }

    private function resolveArtist(string $name, string $musicbrainzId): int
    {
        $hash = $this->artistHash($name);
        if (isset($this->artistsCache[$hash])) {
            return $this->artistsCache[$hash];
        }

        $artist = Artist::firstOrCreate(
            ['hash' => $hash],
            [
                'name'                  => $name,
                'musicbrainz_artist_id' => $musicbrainzId ?: null,
            ],
        );

        if (!$artist instanceof Artist) {
            throw new \RuntimeException("Failed to upsert artist '{$name}'");
        }

        $id = (int) $artist->id;
        $this->artistsCache[$hash] = $id;
        return $id;
    }

    private function resolveAlbum(
        int $artistId,
        string $albumArtist,
        string $album,
        string $genre,
        string $year,
        ?string $replayGainAlbumGain,
        ?string $replayGainAlbumPeak,
        string $musicbrainzAlbumId,
        array $info,
    ): int {
        $hash = $this->albumHash($albumArtist, $album, $year, $musicbrainzAlbumId);
        if (isset($this->albumsCache[$hash])) {
            return $this->albumsCache[$hash];
        }

        $existing = Album::where('hash', $hash)->first();
        if ($existing instanceof Album) {
            $id = (int) $existing->id;
            $this->albumsCache[$hash] = $id;
            return $id;
        }

        ['url' => $coverUrl, 'dominant' => $dominant] = $this->extractAndStoreCover($info);

        $created = Album::create([
            'hash'                  => $hash,
            'artist_id'             => $artistId,
            'title'                 => $album,
            'cover'                 => $coverUrl,
            'dominant_color'        => $dominant,
            'genre'                 => $genre,
            'year'                  => $year,
            'replaygain_album_gain' => $replayGainAlbumGain,
            'replaygain_album_peak' => $replayGainAlbumPeak,
            'musicbrainz_album_id'  => $musicbrainzAlbumId ?: null,
        ]);

        if (!$created instanceof Album) {
            throw new \RuntimeException("Failed to upsert album '{$album}'");
        }

        $id = (int) $created->id;
        $this->albumsCache[$hash] = $id;
        return $id;
    }

    private function artistHash(string $name): string
    {
        return md5($this->normalize($name));
    }

    private function albumHash(string $albumArtist, string $album, string $year, string $mbid): string
    {
        if ($mbid !== '') {
            return md5($mbid);
        }
        return md5(
            $this->normalize($albumArtist)
            . '|' . $this->normalize($album)
            . '|' . $this->normalize($year)
        );
    }

    private function normalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function analyze(string $pathname): array
    {
        try {
            $info = $this->analyzer->analyze($pathname);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($info) || isset($info['error'])) {
            return [];
        }

        $this->analyzer->CopyTagsToComments($info);
        return $info;
    }

    private function extractTags(array $info, SplFileInfo $file): array
    {
        $tag = static fn(string $key): string => isset($info['comments'][$key][0])
            ? trim((string) $info['comments'][$key][0])
            : '';

        $albumArtist = $tag('band');
        if ($albumArtist === '') {
            $albumArtist = $tag('albumartist');
        }
        $artist = $tag('artist');
        if ($artist === '') {
            $artist = $albumArtist;
        }

        // iTunes TCMP / MP4 cpil / Vorbis COMPILATION — collapse comps to one album row.
        if ($tag('part_of_a_compilation') === '1' || $tag('compilation') === '1') {
            $albumArtist = self::VARIOUS_ARTISTS;
        }

        if ($albumArtist === '') {
            $albumArtist = $artist;
        }
        if ($albumArtist === '') {
            $albumArtist = self::UNKNOWN_ARTIST;
        }

        $album = $tag('album');
        if ($album === '') {
            $album = self::UNKNOWN_ALBUM;
        }

        $title = $tag('title');
        if ($title === '') {
            $title = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }

        $genre = $tag('genre');
        if ($genre === '') {
            $genre = self::UNKNOWN_GENRE;
        }

        $year = $tag('year');
        if ($year === '') {
            $year = self::UNKNOWN_YEAR;
        }

        $bitrate = $info['bitrate'] ?? 0;
        if (is_numeric($bitrate)) {
            $bitrate = (string) (int) round($bitrate / 1000);
        }

        $lengthMs = isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])
            ? (int) round(((float) $info['playtime_seconds']) * 1000)
            : null;

        return [
            'album_artist'           => $albumArtist,
            'album'                  => $album,
            'title'                  => $title,
            'genre'                  => $genre,
            'year'                   => $year,
            'track_number'           => $tag('track_number'),
            'disc_number'            => $tag('part_of_a_set'),
            'playtime_string'        => (string) ($info['playtime_string'] ?? ''),
            'length_ms'              => $lengthMs,
            'bitrate'                => (string) $bitrate,
            'mime_type'              => (string) ($info['mime_type'] ?? ''),
            'composer'               => $tag('composer'),
            'bpm'                    => $tag('bpm'),
            'lyrics'                 => $tag('unsynchronised_lyric'),
            'replaygain_track_gain'  => $this->replayGain($info, 'track', 'adjustment'),
            'replaygain_track_peak'  => $this->replayGain($info, 'track', 'peak'),
            'replaygain_album_gain'  => $this->replayGain($info, 'album', 'adjustment'),
            'replaygain_album_peak'  => $this->replayGain($info, 'album', 'peak'),
            'musicbrainz_artist_id'  => $this->mbid($info, 'musicbrainz_artistid', 'MusicBrainz Artist Id'),
            'musicbrainz_album_id'   => $this->mbid($info, 'musicbrainz_albumid',  'MusicBrainz Album Id'),
            'musicbrainz_track_id'   => $this->mbid($info, 'musicbrainz_releasetrackid', 'MusicBrainz Release Track Id')
                ?: $this->mbid($info, 'musicbrainz_trackid', 'MusicBrainz Track Id'),
        ];
    }

    private function replayGain(array $info, string $scope, string $key): ?string
    {
        $val = $info['replay_gain'][$scope][$key] ?? null;
        if ($val === null || $val === '') {
            return null;
        }
        return (string) $val;
    }

    private function mbid(array $info, string $vorbisKey, string $txxxDescription): string
    {
        if (isset($info['comments'][$vorbisKey][0])) {
            return $this->cleanMbid((string) $info['comments'][$vorbisKey][0]);
        }
        foreach ($info['id3v2']['TXXX'] ?? [] as $txxx) {
            if (strcasecmp((string) ($txxx['description'] ?? ''), $txxxDescription) === 0) {
                return $this->cleanMbid((string) ($txxx['data'] ?? ''));
            }
        }
        return '';
    }

    private function cleanMbid(string $value): string
    {
        // Some taggers write TXXX MusicBrainz frames as UTF-16; getid3 leaves the
        // raw bytes (BOM + interleaved nulls) in $info['id3v2']['TXXX'] data.
        $value = preg_replace('/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $value) ?? $value;
        if (str_contains($value, "\0")) {
            $decoded = @mb_convert_encoding($value, 'UTF-8', 'UTF-16LE,UTF-16BE');
            $value = (is_string($decoded) && $decoded !== '') ? $decoded : str_replace("\0", '', $value);
        }
        $value = trim($value);
        // MBIDs are UUIDs (hex + dashes), optionally joined with "/" for multi-artist.
        if ($value === '' || !preg_match('~^[0-9a-f\-/]+$~i', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * @return array{url: ?string, dominant: ?string}
     */
    private function extractAndStoreCover(array $info): array
    {
        $empty = ['url' => null, 'dominant' => null];

        $data = $info['comments']['picture'][0]['data']
            ?? $info['id3v2']['APIC'][0]['data']
            ?? $info['id3v2']['PIC'][0]['data']
            ?? null;

        if (!is_string($data) || $data === '') {
            return $empty;
        }

        $hash     = md5($data);
        $filename = "{$hash}.png";
        $fullPath = rtrim($this->coversPath, '/') . '/' . $filename;

        if (!is_file($fullPath)) {
            $img = @imagecreatefromstring($data);
            if ($img === false) {
                return $empty;
            }
            if (!@imagepng($img, $fullPath)) {
                return $empty;
            }
        }

        return [
            'url'      => $this->publicCovers . $filename,
            'dominant' => $this->coverArt->computeDominantHex($fullPath),
        ];
    }

    private function ensureCoversDir(): void
    {
        if (!is_dir($this->coversPath) && !mkdir($this->coversPath, 0775, true) && !is_dir($this->coversPath)) {
            throw new \RuntimeException("Unable to create covers directory: {$this->coversPath}");
        }
    }

    private function iterateMediaFiles(string $path): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            yield $file;
        }
    }

    private function loadExistingHashes(): array
    {
        return array_fill_keys(Track::query()->pluck('hash'), true);
    }
}
