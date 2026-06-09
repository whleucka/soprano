<?php

namespace App\Services\Soprano;

use App\Models\Artist;

/**
 * Backfills artist photos using only keyless, public sources:
 *
 *   artists.musicbrainz_artist_id
 *      └─ MusicBrainz lookup (inc=url-rels) ─► Wikidata Q-id / Wikipedia URL
 *            ├─ Wikidata EntityData JSON ─► P18 image ─► Commons Special:FilePath
 *            └─ (fallback) Wikipedia REST summary ─► thumbnail
 *
 * Each artist is marked image_checked_at after an attempt so misses aren't
 * retried every run. MusicBrainz requires a descriptive User-Agent and asks
 * for <= 1 request/sec, so MB calls are throttled.
 */
class ArtistImageService
{
    private const USER_AGENT = 'Soprano/1.0 ( https://soprano.williamhleucka.com )';
    private const MB_BASE = 'https://musicbrainz.org/ws/2';
    private const MB_THROTTLE_SECONDS = 1;

    private const SKIP_NAMES = ['Various Artists', 'Unknown Artist'];

    private string $imagesPath;
    private string $publicImages;

    public function __construct()
    {
        $this->imagesPath   = rtrim((string) config('soprano.artist_images_path'), '/');
        $this->publicImages = (string) config('soprano.public_artist_images');
    }

    /**
     * Process artists that have not been checked yet (or, with $recheck, those
     * still missing an image). Always stamps image_checked_at after an attempt.
     *
     * @return object{success:bool,checked:int,found:int,missed:int,failed:int,error:?string}
     */
    public function backfill(int $limit = 0, bool $recheck = false): object
    {
        $checked = 0;
        $found   = 0;
        $missed  = 0;
        $failed  = 0;
        $success = true;
        $error   = null;

        try {
            $this->ensureImagesDir();

            $query = Artist::query();
            $recheck ? $query->whereNull('image') : $query->whereNull('image_checked_at');
            $artists = $query->get($limit);

            foreach ($artists as $artist) {
                $checked++;
                try {
                    $url = $this->resolveImage($artist);
                    if ($url !== null) {
                        $artist->update(['image' => $url, 'image_checked_at' => date('Y-m-d H:i:s')]);
                        $found++;
                    } else {
                        $artist->update(['image_checked_at' => date('Y-m-d H:i:s')]);
                        $missed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    error_log(sprintf(
                        '[soprano artist-images] failed for %s: %s',
                        $artist->name,
                        $e->getMessage(),
                    ));
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object) [
            'success' => $success,
            'checked' => $checked,
            'found'   => $found,
            'missed'  => $missed,
            'failed'  => $failed,
            'error'   => $error,
        ];
    }

    /** Public URL of the stored image, or null if nothing usable was found. */
    private function resolveImage(Artist $artist): ?string
    {
        $mbid = $this->normalizeMbid((string) ($artist->musicbrainz_artist_id ?? ''));
        if ($mbid === '') {
            $mbid = $this->resolveMbidByName((string) $artist->name) ?? '';
        }
        if ($mbid === '') {
            return null;
        }

        $relations = $this->fetchArtistRelations($mbid);
        if ($relations === null) {
            return null;
        }

        // 1) Wikidata P18 — the canonical, highest-quality image.
        $qid = $this->wikidataIdFromRelations($relations);
        if ($qid !== null) {
            $filename = $this->commonsFilenameFromWikidata($qid);
            if ($filename !== null) {
                $url = 'https://commons.wikimedia.org/wiki/Special:FilePath/'
                    . rawurlencode($filename) . '?width=500';
                $stored = $this->downloadAndStore($url, (string) $artist->hash);
                if ($stored !== null) {
                    return $stored;
                }
            }
        }

        // 2) Wikipedia REST summary thumbnail — fallback when there's no P18.
        $thumb = $this->wikipediaThumbFromRelations($relations);
        if ($thumb !== null) {
            $stored = $this->downloadAndStore($thumb, (string) $artist->hash);
            if ($stored !== null) {
                return $stored;
            }
        }

        return null;
    }

    /** MBIDs may be slash-joined for multi-artist credits; take the first valid UUID. */
    private function normalizeMbid(string $mbid): string
    {
        $first = explode('/', trim($mbid))[0];
        return preg_match('/^[0-9a-f-]{36}$/i', $first) ? $first : '';
    }

    private function resolveMbidByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, self::SKIP_NAMES, true)) {
            return null;
        }
        $url = self::MB_BASE . '/artist?query=' . rawurlencode('artist:"' . $name . '"')
            . '&limit=1&fmt=json';
        $data = $this->httpJson($url, true);
        $id = $data['artists'][0]['id'] ?? null;
        return is_string($id) && $this->normalizeMbid($id) !== '' ? $id : null;
    }

    /** @return array<int,array<string,mixed>>|null */
    private function fetchArtistRelations(string $mbid): ?array
    {
        $url = self::MB_BASE . '/artist/' . $mbid . '?inc=url-rels&fmt=json';
        $data = $this->httpJson($url, true);
        return isset($data['relations']) && is_array($data['relations'])
            ? $data['relations']
            : null;
    }

    /** @param array<int,array<string,mixed>> $relations */
    private function wikidataIdFromRelations(array $relations): ?string
    {
        foreach ($relations as $rel) {
            if (($rel['type'] ?? '') !== 'wikidata') {
                continue;
            }
            $resource = (string) ($rel['url']['resource'] ?? '');
            if (preg_match('~/(Q\d+)~', $resource, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function commonsFilenameFromWikidata(string $qid): ?string
    {
        $url = 'https://www.wikidata.org/wiki/Special:EntityData/' . $qid . '.json';
        $data = $this->httpJson($url, false);
        $filename = $data['entities'][$qid]['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
        return is_string($filename) && $filename !== '' ? $filename : null;
    }

    /** @param array<int,array<string,mixed>> $relations */
    private function wikipediaThumbFromRelations(array $relations): ?string
    {
        foreach ($relations as $rel) {
            if (($rel['type'] ?? '') !== 'wikipedia') {
                continue;
            }
            $resource = (string) ($rel['url']['resource'] ?? '');
            if (!preg_match('~^https?://([a-z]+)\.wikipedia\.org/wiki/(.+)$~i', $resource, $m)) {
                continue;
            }
            // $m[2] is already percent-encoded in the resource path; reuse as-is.
            $url = "https://{$m[1]}.wikipedia.org/api/rest_v1/page/summary/{$m[2]}";
            $data = $this->httpJson($url, false);
            $thumb = $data['originalimage']['source'] ?? $data['thumbnail']['source'] ?? null;
            if (is_string($thumb) && $thumb !== '') {
                return $thumb;
            }
        }
        return null;
    }

    /** Download, validate it's a real image, re-encode to JPEG, return public URL. */
    private function downloadAndStore(string $url, string $hash): ?string
    {
        $data = $this->httpGet($url);
        if ($data === null || $data === '') {
            return null;
        }

        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }

        $fullPath = $this->imagesPath . '/' . $hash . '.jpg';
        if (!@imagejpeg($img, $fullPath, 85)) {
            return null;
        }

        return $this->publicImages . $hash . '.jpg';
    }

    /** @return array<string,mixed>|null */
    private function httpJson(string $url, bool $throttle): ?array
    {
        $body = $this->httpGet($url, ['Accept: application/json']);
        if ($throttle) {
            sleep(self::MB_THROTTLE_SECONDS);
        }
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<int,string> $headers */
    private function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        return $body;
    }

    private function ensureImagesDir(): void
    {
        if (!is_dir($this->imagesPath)
            && !mkdir($this->imagesPath, 0775, true)
            && !is_dir($this->imagesPath)
        ) {
            throw new \RuntimeException("Unable to create artist images directory: {$this->imagesPath}");
        }
    }
}
