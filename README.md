# Soprano

Self-hosted music server with a web player. Music, radio, and podcasts.

<img width="1920" height="1200" alt="image" src="https://github.com/user-attachments/assets/b97cb7c3-2ee9-4c5f-b83d-3f9ee757fbcd" />
<div align="center"><small>(frontend)</small></div><br><br>

<img width="1920" height="1200" alt="image" src="https://github.com/user-attachments/assets/948a21cf-4790-40a7-9c91-4f548477c049" />
<div align="center"><small>(backend)</small></div>

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
./echo migrate:run
./echo soprano:sync
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
./echo soprano:sync           # sync tracks, artists, albums, covers
./echo soprano:lyrics         # backfill lyrics from LRCLIB
./echo soprano:artist-images  # backfill artist images
./echo soprano:transcode      # warm the Opus transcode cache
./echo soprano:duplicates     # find and remove duplicate tracks
./echo soprano:trash          # list or purge files trashed by soprano:duplicates
./echo soprano:stations       # report station pool sizes and percentile thresholds
./echo migrate:run            # run pending migrations
```

Run `./echo list` for everything else.

## Testing

```sh
composer test
```

## License

MIT
