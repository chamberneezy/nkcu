# iOS fan app — build prompt

Paste everything below this line into a fresh Claude Code / Xcode agent session.
It's self-contained and doesn't need this repo's `CLAUDE.md` or any prior
conversation — everything the agent needs is in here.

---

Build a native iOS app in **SwiftUI** (Swift 5.9+, async/await, MVVM,
**iOS 16 minimum deployment target**) that shows fans of the football club
NK Croatia Uzwil what's happening on their website — league table, fixtures,
results, live match score, news, and roster. This is a **brand-new, separate**
Xcode project (`nkcu_fan_ios`). There is no existing iOS code to build on, and
this is **not** an admin tool — there is no login, no editing, no write
requests anywhere in this app.

## Scope — read carefully

Build ONLY what's listed under "Screens" below. Do NOT build: any admin/login
flow, goal or squad editing, a fan-shop checkout, or a contact form that posts
anywhere — those either don't apply to a read-only fan app or are handled by
opening the existing mailto: links in Safari/Mail instead of reimplementing
them. If in doubt whether something is in scope, it isn't — ask before adding it.

## Backend — read-only, already live, do not modify

Base URL for development: `https://test26.croatia-uzwil.ch/` (staging). Structure
the networking layer so this base URL is a single config value — production base
URL will be swapped in later (likely `https://croatia-uzwil.ch/`).

All endpoints below are **plain static JSON files served over HTTPS** — `GET`
only, no auth headers, no API key. Poll them; don't try to open a socket or
long-poll.

### `GET {base}/data/uzwil4.json` — league table

```json
{
  "updated": "2026-08-29",
  "league": "4. Liga · Gruppe 8",
  "standings": [
    {
      "rank": 1,
      "team": "FC Neckertal-Degersheim 1",
      "played": 3,
      "wins": 2,
      "draws": 1,
      "losses": 0,
      "penaltyPoints": 2,
      "goalsFor": 10,
      "goalsAgainst": 3,
      "goalDifference": 7,
      "points": 7
    }
  ]
}
```

Note: this file has **no fixtures array**, only `standings`. Model it that way —
don't assume a `fixtures` key exists here.

The club's own row: match `team` against the regex `\buzwil\b` (case-insensitive)
— this matches "FC Uzwil 4" today but is deliberately loose because the team
name can change between seasons. When a row matches, visually highlight it
(brand red/blue) and **relabel the displayed name to "NK Croatia Uzwil"**
instead of showing "FC Uzwil 4" — the underlying JSON keeps the SFV-registered
name because that's what the league association uses.

### `GET {base}/data/spiele.json` — full season fixture list

```json
{
  "fixtures": [
    {
      "date": "20.08.2026",
      "time": "19:00",
      "home": "FC Uzwil 4",
      "away": "HNK Hajduk",
      "homeScore": null,
      "awayScore": null,
      "venue": "Flawilerstrasse, Niederuzwil",
      "league": "4. Liga · Gruppe 8",
      "dummy": true
    },
    {
      "date": "23.08.2026",
      "time": "13:00",
      "home": "FC Uzwil 4",
      "away": "FC Flawil 2",
      "homeScore": 3,
      "awayScore": 5,
      "venue": "Rüti, Henau",
      "league": "4. Liga · Gruppe 8"
    }
  ]
}
```

- `date` is `DD.MM.YYYY`, `time` is `HH:mm` (24h) and can be an **empty string**
  for a not-yet-scheduled kickoff time — handle that.
- `homeScore`/`awayScore` are `null` until the match has been played, then
  filled in by hand after the matchday. A fixture with both scores non-null is
  a past result; both null is upcoming.
- A fixture may carry `"dummy": true` — this is a placeholder entry the site
  keeps around and should be **excluded** from "next match" / "last match"
  logic and from any results list. Don't show it, don't count it.
- "Next match" = earliest fixture (by date+time) with null scores and no
  `dummy` flag. "Last match" = latest fixture with non-null scores.
- Apply the same `\buzwil\b` team-name relabel/highlight rule as the table.

### `GET {base}/data/live_squad.json` — live match state

Only present/populated while a match is in progress or has just finished being
tracked live; otherwise treat 404 or an empty `matches` object as "no live match."

```json
{
  "matches": {
    "23-08-2026-FC-Uzwil-4-FC-Flawil-2": {
      "matchId": "23-08-2026-FC-Uzwil-4-FC-Flawil-2",
      "date": "23.08.2026",
      "home": "FC Uzwil 4",
      "away": "FC Flawil 2",
      "formation": "4-4-2",
      "lineup": {
        "GK": "A. Specchia",
        "LB": "A. Bahorić",
        "CB1": "G. Jozić",
        "CB2": "B. Pelivani",
        "RB": "A. Rozajac",
        "LM": "N. Gonzalez",
        "CM1": "S. Culanić",
        "CM2": "D. Jozić",
        "RM": "X. Hasallari",
        "ST1": "R. Bulić",
        "ST2": "D. Radoš"
      },
      "updated": "2026-08-23T13:08:44Z",
      "goals": [
        { "id": "g_6a8ad6be06d302.09042679", "minute": 7, "scorer": "Gegner", "side": "away" },
        { "id": "g_6a8ad74a7bd958.08794220", "minute": 9, "scorer": "S. Culanić", "side": "home" }
      ],
      "homeScore": 3,
      "awayScore": 5,
      "live": true
    }
  }
}
```

- `matches` is a dictionary keyed by `matchId` (format:
  `DD-MM-YYYY-Home-Team-With-Dashes-Away-Team-With-Dashes` — spaces replaced
  with dashes). Don't try to reverse-parse this key for display data; every
  field you need is already in the value object. Use the key only to look up
  "is there a live entry for today's fixture."
- `formation` is a string like `"4-4-2"` or `"3-5-2"`; `lineup` keys are
  position codes for that formation and are **not fixed across formations** —
  don't hardcode a position list, render whatever keys are present.
- `side` on a goal is `"home"` or `"away"`; `scorer` is `"Gegner"` (German for
  "opponent") for an opposition goal, or the actual player name for a home-side
  goal scored by Uzwil (side is `"home"` in that row, but home isn't always
  Uzwil — check which of `home`/`away` matched `\buzwil\b` to know which side
  is "us" for coloring).
- `live: true` means the match is currently in progress — show a live
  indicator (pulsing dot, "LIVE" badge) and poll this endpoint every ~20–30s
  while this screen is on-screen. `live: false` with scores present means the
  match ended; stop polling and treat it like a normal result.
- Build the live scoreboard, goal timeline, and lineup/formation view as
  **fully native SwiftUI views** — no embedded web widget, no WKWebView
  anywhere in this feature. This is the one part of the spec the club owner
  called out explicitly.

### Team crests

`GET {base}/logos/{Team Name}.gif` — team name with spaces kept as literal
spaces in the path, URL-encode when building the request (`FC Flawil
2.gif` → percent-encoded). Not every team has a logo file; treat a failed
load as "no logo" and fall back to a generic placeholder crest (a plain
circle/monogram is fine) — mirror the website's `onerror` fallback behavior,
don't crash or show a broken-image glyph.

Club crest: `GET {base}/logo.png` (transparent background — make sure it
renders correctly against both light and dark backgrounds/dark mode).

### News — `GET {base}/data/news.json`, live

```json
{
  "items": [
    {
      "id": "n_seed_hallenturnier2027_teaser",
      "createdAt": "2026-09-01T00:00:00Z",
      "date": "Feb 2027",
      "pinned": true,
      "de": { "tag": "Vorankündigung", "title": "...", "excerpt": "...", "text": "<p>...</p>" },
      "hr": { "tag": "Najava", "title": "...", "excerpt": "...", "text": "<p>...</p>" }
    },
    {
      "id": "n_seed_transfers_2026_08",
      "createdAt": "2026-08-15T00:00:00Z",
      "date": "Aug 2026",
      "pinned": false,
      "de": { "tag": "Transfers", "title": "...", "excerpt": "...", "text": "<p>...</p>" },
      "hr": { "tag": "Transferi", "title": "...", "excerpt": "...", "text": "<p>...</p>" }
    }
  ]
}
```

Sort `items` by `createdAt` descending for display — the file isn't
guaranteed pre-sorted. `text` is HTML restricted to `<p>`, `<br>`,
`<strong>`/`<b>`, `<em>`/`<i>` (sanitized server-side on write) — safe to
render with whatever HTML-to-`AttributedString`/HTML-`WKWebView`-free
rendering approach you prefer for the detail view; `excerpt` is a
pre-truncated plain-text summary, safe to show as plain `Text`. This feed is
read-only from this app's side — publishing happens from a separate admin
app, not from here.

**At most one item ever has `"pinned": true`** (the backend enforces this on
write). Mirror what the website does with it: pull the pinned item out of
the list and show it as a featured/highlighted item above the rest — a
distinct visual treatment (not just sorted first), matching how
`renderPinnedBanner()` in `index.html` renders it as a separate banner above
the card grid. When no item is pinned, there's simply nothing to feature —
don't treat that as an error state. The **website also paginates** the
remaining (unpinned) list to 4 visible items with a "show more" toggle
(`NEWS_VISIBLE_COUNT` in `index.html`); consider the same pattern here for
visual consistency with the site, though it's not load-bearing the way the
pin behavior is.

### Roster — no API yet, known gap

There is currently **no JSON feed** for the player roster — on the website
it's hand-written HTML (`.player-card` divs) with German/Croatian text pairs
baked in as HTML attributes, not fetchable as data. Do not attempt to scrape
`index.html` — that's brittle and out of scope.

For v1:
- Ship a **small bundled JSON snapshot** (`RosterSeed.json`) inside the app
  with a handful of realistic placeholder entries in the shape you'd expect a
  future `data/roster.json` to return (name/photo/role per entry, role text
  in German and Croatian).
- Architect the data layer so swapping the bundled seed for a real
  `GET {base}/data/roster.json` later is a one-line change (same
  repository/service pattern as the news/table/fixtures calls) — don't
  hardcode "load from bundle" deep inside the view layer.
- Show a small "content last updated" note on this screen so it's obvious to
  the club owner (not fans, necessarily) that this data is a static snapshot
  bundled at build time, not live.

## Screens

1. **Home** — next match card (or live match card if one is live right now),
   last match result card, a short news teaser list (top 3 from the seed/feed).
2. **Tabelle (Table)** — full standings table from `uzwil4.json`, Uzwil row
   highlighted, sortable columns optional (not required for v1).
3. **Spielplan (Fixtures)** — full season list from `spiele.json`, split into
   upcoming / past sections, each row shows crest, opponent, date/time, venue,
   and score once played.
4. **Live match** (reachable from Home when `live_squad.json` has a live entry
   for today) — running score, minute-by-minute goal feed, formation/lineup.
5. **News** — list + detail view, backed by the live `data/news.json` feed.
6. **Kader (Roster)** — grouped by team/section (mirror the website's
   grouping: board/coaching staff as one group, then squad), backed by the
   bundled seed (see "Roster" below).
7. **Info / Settings** — language toggle (see Localization below), app
   version, links that open Safari/Mail: shop, contact, sponsors page on the
   live site (don't rebuild these as native screens for v1 — link out).

## Localization

The website manually toggles between German and Croatian via a JS function
that swaps `data-de`/`data-hr` attributes — that's a workaround for not having
a real localization system, don't copy that mechanic here. Instead:

- Use **Swift String Catalogs** (`.xcstrings`) with `de` and `hr` translations.
  Default language is **German** regardless of device locale (matches the
  website's default), with an **in-app manual override** (not just system
  locale) persisted in `UserDefaults`, since that's the toggle behavior fans
  are used to from the site.
- Every user-visible string needs both a German and Croatian value — no
  English fallback strings left in shipped copy.

## Branding

- Colors (Croatian flag palette only — don't introduce other accent colors):
  `red = #CC0000`, `blue = #003580`, `white = #FFFFFF`.
- Headings font: **Clash Display** — variable TTF and static OTF weights are
  already available at `Fonts/ClashDisplay/` in the main website repo; copy
  the OTF files you need into the Xcode project, add them to `Info.plist`
  under `UIAppFonts`, and register a `Font` extension for headings/buttons/labels.
- Body font: the website uses "Satoshi" but no Satoshi font files exist in the
  repo to copy — **flag this explicitly** rather than silently substituting
  something. Use `SF Pro`/system font for body text until Satoshi's actual
  font files are sourced and provided.
- App icon: base it on `logo.png` (club crest, transparent background) — this
  needs a proper icon asset export (opaque background required for iOS app
  icons, various sizes). Treat generating the actual icon set as a design
  task fed by that source image, not something to invent from scratch.

## Non-goals (repeated for emphasis)

No login or admin mode. No write requests to any endpoint. No live-score data
source other than `live_squad.json` (do not embed any third-party score
widget). No full offline parity requirement — caching the last successfully
fetched JSON for offline display is enough; don't build a sync engine.

## Before you start

Replace `{base}` throughout with the actual staging URL given above, and keep
it as a single configurable value so switching to production later is trivial.
