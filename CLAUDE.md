# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Static website for NK Croatia Uzwil — a Croatian football club (4. Liga OFV) in Uzwil, Switzerland. Founded 1971, sub-section of FC Uzwil since 2011.

**No build system.** The entire site is `index.html` + `style.css` with vanilla JS inlined at the bottom of `index.html`. Open directly in a browser or run a local server:

```
python3 -m http.server 8080
# or
npx serve .
```

## Architecture

### Bilingual i18n (DE / HR)

All user-visible text is duplicated into `data-de="..."` and `data-hr="..."` attributes on the element. The JS `applyLang(lang)` function at the bottom of `index.html` walks all `[data-de][data-hr]` elements and sets `textContent` (leaf nodes) or `innerHTML`-safe attribute values accordingly. The current language is stored in `currentLang` (defaults to `'de'`).

Consequence: **never hard-code visible text** — always add both `data-de` and `data-hr` attributes. Form placeholder strings are handled separately in `applyLang` because you can't use data attributes on input placeholders directly.

### News modal

News cards are `<button class="news-card">` elements. All modal content lives in data attributes on the button (`data-de-title`, `data-hr-title`, `data-de-text`, `data-hr-text`, `data-date`, `data-de-tag`, `data-hr-tag`). The `openModal(card)` function reads these and populates `#newsModal`. HTML markup is allowed inside `data-*-text` attributes and is injected via `innerHTML`.

### Fan item order form

The `#fanForm` submit handler reads qty inputs from `.fan-item` elements, builds a mailto body, and opens `mailto:info@croatia-uzwil.ch`. No backend — fully client-side.

### Contact form

Uses `action="mailto:info@croatia-uzwil.ch" method="get"` — opens the native mail client, no server needed.

## Design tokens (style.css `:root`)

| Token | Value | Use |
|---|---|---|
| `--red` | `#CC0000` | Primary CTA, accents |
| `--blue` | `#003580` | Contact section bg, footer |
| `--white` | `#FFFFFF` | Backgrounds, text on dark |
| `--ff-head` | Clash Display | Headings, buttons, labels |
| `--ff-body` | Satoshi | Body text |

Croatian flag palette: red / white / blue only. Avoid introducing other accent colours.

## Content updates

- **Match results / next match**: edit the `.match-card` blocks in the `#news` section of `index.html`
- **News cards**: add/edit `<button class="news-card">` elements with the full set of data attributes
- **Player roster**: add `.player-card` divs in the appropriate `.team-block`; place photo in `players/`; use `.player-photo--placeholder` SVG when no photo is available
- **Sponsors**: edit `.active-sponsors` rows and `.sponsor-packages` cards
- **Shop items**: edit `.shop-card` divs — images are hotlinked from 11teamsports CDN
- don't upload CLAUDE.md file
- do not publish on the Plesk automatically, you will get instructions when to push the changes

## Assets

- `logo.png` — club crest (transparent background); use class `mol-logo--transparent` or `footer-logo-img--transparent` where the background varies
- `players/` — player headshots (named `firstname-lastname.jpg`)
- `hero-video.mp4` / `hero-team.png` — hero background (video with image fallback)

- Fonts loaded from `https://api.fontshare.com` — requires internet access to render correctly
