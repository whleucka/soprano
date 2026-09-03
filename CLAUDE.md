# soprano

## Front-end assets are cache-busted by hand

There is no asset pipeline and no build step for `public/css/*.css` and
`public/js/*.js`. They are served as-is, and staleness is managed manually in
two independent layers. **A change to a css or js file is not finished until
both are bumped, in the same commit.**

### 1. The asset's own `?v=` query string

Each asset is referenced from exactly one template. Bump only the ones you
actually edited:

| asset | reference site |
|---|---|
| `public/js/app.js` | `templates/layout/app.html.twig` (~line 4) |
| `public/css/app.css` | `templates/layout/app.html.twig` (~line 8) |
| `public/css/style.css` | `templates/layout/base.html.twig` (~line 15) |
| `public/js/script.js` | `templates/layout/base.html.twig` (~line 20) |
| `public/js/player.js` | `templates/player/index.html.twig` (~line 17) |
| `public/js/playlist.js` | `templates/playlist/index.html.twig` (~line 2) |
| auth page assets | `templates/soprano/auth/sign-in/index.html.twig`, `.../register/index.html.twig` (~line 6) |

Format is `?v=YYYYMMDD`, with a suffix letter when a date is reused the same
day: `?v=20260902a`, then `?v=20260902b`.

### 2. The service worker, always

`public/sw.js`:

```js
const CACHE_VERSION = '2026-08-19';   // ~line 11
```

Its `STATIC_PATH` regex is `/^\/(css|js|fonts|icons|images|covers)\//`, so it
caches **every** asset regardless of which one you changed. Bump it for any
asset edit. Note the different date format here: `2026-09-03`, not `20260903`.

This is the one that gets missed, because it is not under `templates/` and the
symptom is delayed: `stale-while-revalidate` means a user keeps being served
the old file even after the query string changed. A diff that updates the CSS
and the `?v=` still looks correct in review and still ships nothing.

## Where this runs

`~/projects/soprano` is the live tree — docker mounts it, so the running site
serves from that working copy. Agent work happens in `~/agent/soprano` and
reaches production only through a reviewed PR to `main`.

## CI

The required check on pull requests is the job named `build`.
