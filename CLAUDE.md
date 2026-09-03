# soprano

## Front-end assets are cache-busted by hand

There is no asset pipeline and no build step for `public/css/*.css` and
`public/js/*.js`. They are served as-is, and staleness is managed manually in
two independent layers. **A change to a css or js file is not finished until
both are bumped, in the same commit.**

### 1. The asset's own query string

The table below lists every reference site. Bump only the ones you actually
edited. **Two conventions are in use** — the admin layout uses `?d=` and a
dashed date, everything else uses `?v=` and a compact one. Match whatever the
line you are editing already uses; do not normalise them:

| asset | reference site | buster |
|---|---|---|
| `public/js/app.js` | `templates/layout/app.html.twig` (~line 4) | `?v=` |
| `public/css/app.css` | `templates/layout/app.html.twig` (~line 8) | `?v=` |
| `public/css/style.css` | `templates/layout/base.html.twig` (~line 15) | `?v=` |
| `public/js/script.js` | `templates/layout/base.html.twig` (~line 20) | `?v=` |
| `public/js/player.js` | `templates/player/index.html.twig` (~line 17) | `?v=` |
| `public/js/playlist.js` | `templates/playlist/index.html.twig` (~line 2) | `?v=` |
| auth page assets | `templates/soprano/auth/sign-in/index.html.twig`, `.../register/index.html.twig` (~line 6) | `?v=` |
| `public/js/admin.js` | `templates/layout/admin.html.twig` (~line 4) | `?d=` |
| `public/css/admin.css` | `templates/layout/admin.html.twig` (~line 13) | `?d=` |
| `public/css/admin-dark.css` | `templates/layout/admin.html.twig` (~line 14) | `?d=` |

`?v=` format is `YYYYMMDD`, with a suffix letter when a date is reused the same
day: `?v=20260902a`, then `?v=20260902b`.

`?d=` format is dashed `YYYY-MM-DD` — the same shape as `sw.js` below, not the
compact `?v=` one — with the same suffix-letter rule on a reused date:
`?d=2026-09-02`, then `?d=2026-09-02b`.

### 1a. The admin layout

`templates/layout/admin.html.twig` is the only place the three `?d=` assets are
referenced, and `templates/admin/module.html.twig` is the only template that
extends it. Two things about it are easy to get wrong:

- It `extends "layout/base.html.twig"`, so admin pages load `style.css` and
  `script.js` from base **in addition to** the three assets above. A change to
  `style.css` or `script.js` therefore affects admin pages too; bump it at its
  base reference site in the table above — there is no admin-specific copy.
- Its vendor assets carry **no** cache-buster on purpose: `chart.js`,
  `jsvectormap.min.js`, `jsvectormap-world.js`, `jsvectormap.min.css`,
  `bootstrap-icons.min.css` and `flag-icons.min.css` are pinned third-party
  files that we do not edit, so there is nothing to invalidate. Leave them bare;
  they are not an oversight. (Base does the same with `bootstrap.min.css`,
  `htmx.min.js`, `head-support.js`, `hls.min.js` and `bootstrap.bundle.min.js`.)

### 2. The service worker, always

`public/sw.js`:

```js
const CACHE_VERSION = '2026-09-03a';   // ~line 11
```

Its `STATIC_PATH` regex is `/^\/(css|js|fonts|icons|images|covers)\//`, so it
caches **every** asset regardless of which one you changed. Bump it for any
asset edit. Note the different date format here: dashed `YYYY-MM-DD`, not the
compact `?v=` one.

What the bump buys you depends on the asset, because `NETWORK_FIRST`
(`/^\/(css|js)\//`, ~line 15) splits the fetch handler in two:

- css and js are **network-first** — fetched from the network on every request,
  with the cache used only as an offline fallback. For these, freshness rests on
  the `?v=` / `?d=` query string and the browser's own HTTP cache; the service
  worker is not what serves someone a stale stylesheet, so do not start looking
  there.
- fonts, icons, images and covers are **stale-while-revalidate** — served from
  cache first, so `CACHE_VERSION` is what makes a changed one appear.

Bumping it also renames the cache, which is how `activate` evicts the previous
generation. It is still the step that gets missed, because it is not under
`templates/`.

## Where this runs

`~/projects/soprano` is the live tree — docker mounts it, so the running site
serves from that working copy. Agent work happens in `~/agent/soprano` and
reaches production only through a reviewed PR to `main`.

## CI

The required check on pull requests is the job named `build`.
