# nkcu_ios admin app — add a News tab

Paste everything below this line into a Claude Code / Xcode agent session that
has the **existing** `nkcu_ios` project open. This is not a new project — it's
a feature addition to the same admin app that already handles live-match
goals and squad/lineup management. Match that app's existing patterns
(networking layer, auth/session handling, navigation structure, design
system) rather than introducing new ones; everything below describes the
feature, not the app's overall architecture, which you should read from the
existing code first.

---

Add a **News** feature to the app so admins can publish, edit, and delete the
items shown in the "News & Anlässe" section of croatia-uzwil.ch, instead of
that content only ever being hand-edited in the website's JSON file.

## Where it lives in the UI

- Add a news icon (e.g. SF Symbol `newspaper` or `newspaper.fill`) in the
  top navigation bar, **next to the existing profile icon in the top-right
  corner** — tapping it pushes/presents a News screen.
- The News screen lists all current news items (see "List screen" below).
- In the **bottom-right corner** of the News screen, add a floating **+**
  button that opens the compose screen for a brand-new item.
- Tapping an existing item in the list opens the same compose screen
  pre-filled, in edit mode (see "Compose/edit screen" below).

## Backend — already live, do not modify

Base URL: same one the rest of the app already uses (staging:
`https://test26.croatia-uzwil.ch/`; production URL is whatever the rest of
the app is already configured with — don't hardcode a second copy, reuse the
existing config).

### `GET {base}/data/news.json` — list

Plain static JSON, no auth needed to read it (same as how the rest of the
public site's data files work):

```json
{
  "items": [
    {
      "id": "n_seed_transfers_2026_08",
      "createdAt": "2026-08-15T00:00:00Z",
      "date": "Aug 2026",
      "de": { "tag": "Transfers", "title": "...", "excerpt": "...", "text": "<p>...</p>" },
      "hr": { "tag": "Transferi", "title": "...", "excerpt": "...", "text": "<p>...</p>" }
    }
  ]
}
```

Sort by `createdAt` descending for display (the file itself is not
guaranteed to be pre-sorted). `date` is a short display label like `"Aug
2026"` — server-generated, don't let the compose screen edit it directly.
`excerpt` is server-generated too (a truncated plain-text summary of `text`)
— don't build a UI field for it.

### `POST {base}/save-news.php` — create or update

Requires the same shared admin password the rest of this app already sends
on writes, plus (optionally, like the goal/squad endpoints) the current
user's session token so the change is attributable.

Request body:

```json
{
  "password": "<the shared admin password already used elsewhere in the app>",
  "token": "<current user's session token, if logged in>",
  "id": "n_xxx",
  "deTag": "Transfers",
  "hrTag": "Transferi",
  "deTitle": "Sommertransfers 2026/27",
  "hrTitle": "Ljetni transferi 2026./27.",
  "deText": "<p>Text mit <strong>bold</strong> und <em>italic</em>.</p>",
  "hrText": "<p>Tekst s <strong>podebljano</strong> i <em>kurziv</em>.</p>"
}
```

- Omit `id` (or send an empty string) to **create** a new item — the server
  generates the id. Send an existing item's `id` to **update** it in place.
- `deText`/`hrText` are HTML strings. The server sanitizes them to an
  allow-list of `<p>`, `<br>`, `<strong>`, `<b>`, `<em>`, `<i>` and strips
  everything else (including all attributes) — so don't bother sending
  anything richer than that; it'll just be silently flattened to plain text.
- Response: `{"ok": true, "item": {...}}` on success, `{"ok": false, "error":
  "..."}` with a 4xx/5xx status otherwise (401 for a wrong password, 400 for
  missing fields).

### `POST {base}/delete-news.php` — delete

```json
{
  "password": "<shared admin password>",
  "token": "<session token, if logged in>",
  "id": "n_xxx"
}
```

Response: `{"ok": true}` or `{"ok": false, "error": "..."}` (404 if the id
doesn't exist).

## List screen

- Fetch `data/news.json`, show each item as a row: tag chip, title (German —
  showing both languages in a list row is unnecessary clutter; the compose
  screen edits both), the `date` label.
- Pull-to-refresh re-fetches.
- Swipe-to-delete (or an edit-mode delete button) calls `delete-news.php`,
  then removes the row optimistically / re-fetches on success. Confirm with
  an alert before deleting — this is irreversible from the app's side (the
  JSON file has no trash/undo).
- Tapping a row opens the compose screen pre-filled with that item's `id` and
  current field values (edit mode).

## Compose/edit screen

Fields, in this order:

1. **Tag** — a picker with the tags already in use on the site as preset
   options, each option carrying both languages together (so picking one
   sets both `deTag` and `hrTag` at once, they're not independently chosen):
   - Transfers / Transferi
   - Saison / Sezona
   - Trainingslager / Pripreme
   - Hallenturnier / Dvoranski turnir
   - Plus a "Custom…" option that reveals two free-text fields (DE tag, HR
     tag) for a tag that doesn't exist yet — don't hardcode the preset list
     as the only option forever, club news categories will grow.
2. **Title (German)** — single-line text field.
3. **Title (Croatian)** — single-line text field.
4. **Message (German)** — multi-line rich text field, **bold and italic
   only** — see "Rich text editing" below. Nothing else (no underline, no
   font size/color, no lists, no links) — keep this deliberately minimal.
5. **Message (Croatian)** — same control as above, independent content.

A **Save** button (disabled until tag + both titles + both messages are
non-empty) calls `save-news.php` with the current field values and the
existing `id` if editing, or no `id` if creating. On success, pop back to the
list and refresh it. On failure, show the server's `error` message.

### Rich text editing (bold/italic only)

Don't reach for a general-purpose rich-text/Markdown library for this —
it's two toggle buttons. A reasonable approach:

- Back each message field with `NSAttributedString` (e.g. a `UITextView`
  wrapped for SwiftUI, or `TextEditor` if your deployment target's SwiftUI
  attributed-string support covers this — check what the rest of the app
  already uses for text input before picking).
- A small toolbar above/below the field with **B** and *I* toggle buttons
  that apply/remove bold/italic on the current selection (standard
  `UIFont.fontDescriptor.withSymbolicTraits` toggling on the selected range).
- On save, walk the attributed string's runs and emit HTML: wrap
  bold+italic runs in `<strong><em>…</em></strong>`, bold-only in
  `<strong>…</strong>`, italic-only in `<em>…</em>`, plain runs as escaped
  text, and treat each paragraph (line break in the text view) as its own
  `<p>…</p>`. Escape `<`, `>`, `&` in plain text runs before wrapping — the
  server sanitizes too, but the app shouldn't rely on that as its only
  defense against producing broken HTML.
- When **editing** an existing item, do the reverse on load: parse the
  stored `<p>`/`<strong>`/`<b>`/`<em>`/`<i>`/`<br>` HTML into an
  `NSAttributedString` (a plain `NSAttributedString(data:options:
  documentAttributes:)` HTML import is fine for this restricted tag set) so
  existing formatting round-trips instead of showing raw HTML tags.

## Non-goals

No image/photo attachments on news items (the site's news cards are
text-only today). No scheduling/draft state — everything saved is published
immediately. No per-tag color customization in the compose UI. No changes to
the goals/squad tabs or their endpoints.
