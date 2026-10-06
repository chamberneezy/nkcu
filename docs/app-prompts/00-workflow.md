# NK Croatia Uzwil — fan companion apps: workflow

This folder holds the specs for two **new, separate** native mobile apps that mirror
the public-facing part of croatia-uzwil.ch (news, table, fixtures, live match,
roster) on phones. This is **not** the existing `nkcu_ios` project — that one is a
private admin tool for match-day staff (goals/squad editing) and stays untouched.
See [[project_nkcu_ios_companion_app]] in memory if you need that context again.

Files in this folder:

- `ios-app-prompt.md` — self-contained brief for a new Xcode project, SwiftUI,
  the read-only public fan app.
- `android-app-prompt.md` — self-contained brief for a new Android Studio
  project, Kotlin + Jetpack Compose, same fan app.
- `ios-admin-news-feature-prompt.md` — the spec a News tab addition to the
  existing `nkcu_ios` admin app was built from. **Now historical, not a
  pending task** — the feature (list, compose, bold/italic editor, tag
  picker, pin toggle) was built directly in `nkcu_ios` on 2026-09-14/15 and
  is live in production. Kept here for reference, e.g. if the feature ever
  needs rebuilding from scratch. See [[project_nkcu_ios_companion_app]] for
  the full build/verification history.

Each prompt is written to be pasted into a fresh coding-agent session on its own —
it doesn't assume the agent has seen this repo's `CLAUDE.md` or this conversation.

## Assumption worth double-checking

You said "for the live, I want it to be fully native." These prompts spec **both
whole apps** as fully native (SwiftUI / Compose), not just the live-match screen
wrapped around a web view for everything else. A half-native/half-webview app is
more work to get right (two rendering systems, inconsistent feel) than a fully
native one, so that's the default here. If you actually wanted a cheap webview
shell with only live scores built natively, say so and these get rewritten —
easy to change before any code exists, expensive after.

## What's already live vs. what's missing

The public site already serves three machine-readable JSON files the apps can
poll directly, no new backend needed for v1:

| File | Contains |
|---|---|
| `data/uzwil4.json` | League table (`standings[]`) — no fixtures in it currently, despite older docs saying otherwise |
| `data/spiele.json` | Full season fixture list (`fixtures[]`), scores filled in after each match |
| `data/live_squad.json` | Live match state during a game in progress: lineup, formation, goals, running score, `live` flag |

**Update 2026-09-14 — news is no longer a gap, and this shipped to
production.** `data/news.json` exists and is live on **both**
`test26.croatia-uzwil.ch` and `www.croatia-uzwil.ch`: `index.html` renders
its news cards from it (`fetchNews()`/`renderNewsCards()`), written by two
endpoints, `save-news.php` (create/update) and `delete-news.php` (delete) —
authenticated the same way as `save-goal.php`. Publishing happens from the
**existing** `nkcu_ios` admin app's News tab (built, not just specced — see
above), not from a web UI. Both fan-app prompts below read `GET
{base}/data/news.json` directly for their News screen instead of a bundled
seed — see the inline note in each prompt's "News" section.

At most one item can carry `"pinned": true` (enforced server-side); the
website pulls it out and renders it as a distinct featured banner above the
card grid (`renderPinnedBanner()`), and paginates the rest 4-at-a-time behind
a "show more" toggle. Both fan-app prompts now document this — match it for
visual consistency with the site, though only the pin-exclusivity guarantee
itself is load-bearing (the UI treatment is a recommendation, not a spec).

Also added the same day: a public-facing security hardening pass on both
environments (an `.htaccess` that blocks `data/users.json`,
`data/sessions.json`, `.env`, `.git`, `*.p8`, and disables directory
listing — none of it affects the public `data/*.json` files these apps
read) and a self-service "Change Password" screen in `nkcu_ios` (`Services/
AuthService.swift`'s `changePassword()` + `Views/ChangePasswordSheet.swift`).
Neither is relevant to building the fan apps, just noted here so the picture
of "what's live on these domains now" stays accurate.

**Remaining gap: the player roster.** Still hand-written HTML (`.player-card`
divs) inside `index.html`, no JSON feed. Same treatment as before: each fan
app prompt flags this and ships a bundled static snapshot for v1. If a
`data/roster.json` + admin-app editor ever gets built (mirroring what just
happened for news), update both fan-app prompts the same way.

## Phased plan

1. **(This step)** Write and review these two prompt files.
2. **(Recommended, do first)** Add `data/news.json` + `data/roster.json` to the
   website so both future apps have a real feed. Small, independent task.
3. Hand `ios-app-prompt.md` to a fresh Claude Code / Xcode session to scaffold
   the new `nkcu_fan_ios` project. Hand `android-app-prompt.md` to a fresh
   session (Android Studio) for `nkcu_fan_android`. Build them independently —
   neither depends on the other existing.
4. Internal testing: TestFlight (iOS) / internal testing track (Play Console)
   against the **staging** subdomain first, same pattern as the existing admin
   app used `https://test26.croatia-uzwil.ch/`.
5. Store listings: both stores require a privacy policy URL and a data-safety /
   privacy "nutrition label" questionnaire even for a fully read-only app with
   no accounts — croatia-uzwil.ch doesn't appear to have a privacy policy page
   today, so that needs to exist (a simple static page is enough) before
   submission.
6. **Optional phase 2, after v1 ships:** push notifications for live goals.
   - iOS: reuse the existing `apns.php` key, but add a **new** device-token
     store/topic separate from the admin app's (`register-device.php` is
     session-authenticated for admins; fans have no login, so this needs an
     new, unauthenticated `register-device-public.php` — rate-limit it).
   - Android: APNs doesn't reach Android at all — this means standing up
     Firebase Cloud Messaging from scratch (new Firebase project, server key,
     separate integration). Budget this as real backend work, not a copy-paste
     of the iOS piece.

## Guardrails

- Never put the shared admin password or the APNs `.p8` key/Key ID/Team ID
  into either app or its prompt file — these fan apps have no admin mode and
  no login; if one ever gets an admin toggle later, treat its credentials as a
  fully separate flow from `save-goal.php`/`save-squad.php`'s.
- These `.md` files are planning docs for a coding agent, not site content —
  don't add them to anything that gets deployed, and (per existing project
  rules) nothing here gets pushed to Plesk without an explicit go-ahead.
- If the shape of `uzwil4.json` / `spiele.json` / `live_squad.json` changes on
  the website later, the "Backend" section in both app prompts goes stale —
  re-diff them against the real files before starting a big feature pass.
