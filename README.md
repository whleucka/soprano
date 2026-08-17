# Soprano

<img width="1920" height="1200" alt="image" src="https://github.com/user-attachments/assets/3a9931a2-2a5c-4e9a-9c5d-5aae477307ea" />

Self-hosted music server with a web player. Music, radio, and podcasts.

## Features

- Stream your own music library (FLAC, MP3, and more, with Opus transcoding)
- Search across tracks, albums, and artists
- Playlists, liked tracks, and play queue with repeat modes
- Auto-generated playlists built from audio analysis (Essentia) and listening history
- Artist radio and curated internet radio stations (skip-aware, tunable station pools)
- Crossfade between tracks and replay gain volume normalization
- Podcasts with saved progress and continue listening
- Synced lyrics via LRCLIB
- Cover art and artist images via MusicBrainz and Wikidata (no API keys)
- Year end review
- Persistent login, sign in once per device
- Admin dashboard with activity logs and library management

## Stack

- PHP 8.4 on Echo (custom framework, included in this repo)
- MariaDB, Redis, nginx, Docker Compose
- Twig and HTMX on the frontend

## Requirements

- Docker and Docker Compose
- A music library on disk

## Quick start

```sh
git clone https://github.com/whleucka/soprano.git
cd soprano
cp .env.example .env
```

- Edit `.env`, set `MUSIC_DIR` to your music library path
- Start the dev stack:

```sh
./bin/dev up -d --build
./bin/php bin/console migrate:run
./bin/php bin/console soprano:sync
```

- Open `http://localhost:8083` and register an account

## Production

- Set `PROD_HOSTNAME`, `BUILD_MODE=prod`, and `APP_DEBUG=false` in `.env`
- Traefik handles TLS via Let's Encrypt

```sh
./bin/prod up -d --build
```

## Console

Commands run inside the php container via `./bin/php`:

```sh
./bin/php bin/console soprano:sync           # sync tracks, artists, albums, covers
./bin/php bin/console soprano:lyrics         # backfill lyrics from LRCLIB
./bin/php bin/console soprano:artist-images  # backfill artist images
./bin/php bin/console soprano:transcode      # warm the Opus transcode cache
./bin/php bin/console soprano:duplicates     # find and remove duplicate tracks
./bin/php bin/console soprano:trash          # list or purge files trashed by soprano:duplicates
./bin/php bin/console soprano:stations       # report station pool sizes and percentile thresholds
./bin/php bin/console migrate:run            # run pending migrations
```

Run `./bin/php bin/console list` for everything else.

## Testing

```sh
composer test
```

## License

MIT
