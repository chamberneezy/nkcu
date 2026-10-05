// Match details rendering shared by index.html and saison.html: the score
// card (lsRenderCard), the lineup pitch with player cut-outs (lsRenderPitch)
// and their helpers. One copy, so the match modal looks the same on every
// page. Plain top-level declarations on purpose: both pages' inline scripts
// call these as globals. Name -> photo map (PHOTO_SLUG) must match the apps.

var FORMATIONS = {
  '4-4-2': {
    GK: ['GK'],
    DEF: ['LB', 'CB1', 'CB2', 'RB'],
    MID: ['LM', 'CM1', 'CM2', 'RM'],
    ATT: ['ST1', 'ST2']
  },
  '3-5-2': {
    GK: ['GK'],
    DEF: ['CB1', 'CB2', 'CB3'],
    MID: ['LWB', 'DM', 'CM1', 'CM2', 'RWB'],
    ATT: ['ST1', 'ST2']
  },
  '4-3-3': {
    GK: ['GK'],
    DEF: ['LB', 'CB1', 'CB2', 'RB'],
    MID: ['CM1', 'CM2', 'CM3'],
    ATT: ['LW', 'ST', 'RW']
  }
};

var LINE_Y = { ATT: 14, MID: 40, WB: 54, DEF: 65, GK: 88 };

/* 3-5-2 packs 5 rows (GK/DEF/WB/MID/ATT) into the same pitch height
   as every other formation's 4, so its central three and back three
   sit closer to the WB row than they'd like — nudge MID up and DEF
   down a touch so the rows don't visually overlap. */
var LINE_Y_OVERRIDES = {
  '3-5-2': { MID: 35, DEF: 70 }
};

function lsLineY(formation, line) {
  var o = LINE_Y_OVERRIDES[formation];
  return (o && o[line] != null) ? o[line] : LINE_Y[line];
}

/* 3-5-2's midfield five isn't one flat line on the pitch — the
   wing-backs sit a little higher than the back three, with the
   central three further forward still. Slots are still stored
   under MID (admin.html's slot picker doesn't need to know about
   this), but rendering splits them onto their own "WB" row. */
var SUBLINES = {
  '3-5-2': { MID: { LWB: 'WB', RWB: 'WB' } }
};

function lsEffectiveLine(formation, line, slot) {
  var map = SUBLINES[formation] && SUBLINES[formation][line];
  return (map && map[slot]) || line;
}

/* Short-name ("N. Surname") → transparent squad photo. null = no photo yet. */
var PHOTO_SLUG = {
  'A. Specchia': 'alessio-specchia', 'R. Vičić': 'robert-vicic', 'D. Brkljača': 'damjan-brkljaca',
  'A. Ramdedović': 'anis-din-ramdedovic', 'L. Gojević': 'luka-gojevic',
  'G. Jozić': 'gabrijel-jozic', 'K. Katičić': 'kristijan-katicic', 'M. Breljak': 'martin-breljak',
  'D. Martić': 'dario-martic',
  'I. Begić': 'ivan-begic', 'A. Marić': 'antonio-maric', 'D. Ajrizi': 'denis-ajrizi',
  'B. Pelivani': 'bruno-pelivani', 'A. Rozajac': 'adel-rozajac', 'X. Hasallari': 'xhevat-hasallari',
  'N. Gonzalez': 'noel-gonzalez', 'H. Haileselassie': 'henok-haileselassie', 'M. Gojević': 'matej-gojevic',
  'M. Matić': null, 'A. Bahorić': 'adrian-bahoric', 'J. Baumann': null, 'I. Schwab': null,
  'S. Culanić': 'stanko-culanic', 'D. Jozić': 'danijel-jozic', 'S. Bahorić': 'silvio-bahoric',
  'D. Uka': 'dionis-uka', 'I. Martić': null, 'S. Cucinelli': 'saverio-cucinelli', 'I. Beka': null,
  'R. Bulić': 'robertino-bulic', 'K. Perić': 'kristijan-peric',
  'S. Ivanković': 'stefan-ivankovic', 'D. Radoš': 'davor-rados', 'H. Hysenaj': 'hazir-hysenaj'
};

var JERSEY_SVG = '<svg class="lsp-jersey" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' +
  '<defs><pattern id="lspCheckers" width="20" height="20" patternUnits="userSpaceOnUse">' +
  '<rect width="20" height="20" fill="#0055d4"/><rect width="10" height="10" fill="#111111"/>' +
  '<rect x="10" y="10" width="10" height="10" fill="#111111"/></pattern>' +
  '<clipPath id="lspClip"><path d="M 30,18 Q 40,26 50,26 Q 60,26 70,18 L 86,28 L 78,44 L 72,40 L 72,82 Q 72,85 68,85 L 32,85 Q 28,85 28,82 L 28,40 L 22,44 L 14,28 Z"/></clipPath></defs>' +
  '<path d="M 30,18 Q 40,26 50,26 Q 60,26 70,18 L 86,28 L 78,44 L 72,40 L 72,82 Q 72,85 68,85 L 32,85 Q 28,85 28,82 L 28,40 L 22,44 L 14,28 Z" fill="url(#lspCheckers)"/>' +
  '<g clip-path="url(#lspClip)"><path d="M 30,18 L 14,28 L 22,44 L 32,36 Z" fill="#111111"/>' +
  '<path d="M 70,18 L 86,28 L 78,44 L 68,36 Z" fill="#111111"/></g>' +
  '<path d="M 30,18 Q 40,26 50,26 Q 60,26 70,18 Q 60,22 50,22 Q 40,22 30,18 Z" fill="#0055d4"/></svg>';

function lsEsc(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function lsMatchId(f) {
  return (f.date + '__' + f.home + '__' + f.away).replace(/[^a-zA-Z0-9]+/g, '-');
}

function lsIsUs(name) { return !!(name && /\buzwil\b/i.test(name)); }
function lsDisplayName(name) { return lsIsUs(name) ? 'NK Croatia Uzwil' : name; }

function lsTeamLogoSrc(name) {
  if (lsIsUs(name)) return 'logo.png';
  var base = String(name).trim().replace(/\s+\d+[a-z]?$/i, '');
  return 'logos/' + encodeURIComponent(base) + '.gif';
}

function lsNameHtml(name) {
  var esc = lsEsc(name);
  var idx = esc.indexOf(' ');
  if (idx === -1) return esc;
  return esc.slice(0, idx) + ' <span class="mol-name-break">' + esc.slice(idx + 1) + '</span>';
}

function lsTeamHtml(name, isRight) {
  var isUs = lsIsUs(name);
  var nameHtml = '<span class="mol-name' + (isUs ? ' mol-name--us' : '') + '">' + lsNameHtml(lsDisplayName(name)) + '</span>';
  var logoHtml = '<img src="' + lsTeamLogoSrc(name) + '" alt="' + lsEsc(lsDisplayName(name)) + '" class="mol-logo mol-logo--img mol-logo--transparent" onerror="this.style.display=\'none\'" />';
  var inner = isRight ? (nameHtml + logoHtml) : (logoHtml + nameHtml);
  return '<div class="mol-team' + (isRight ? ' mol-team--right' : '') + '">' + inner + '</div>';
}

function lsPad(n) { n = String(n); return n.length < 2 ? '0' + n : n; }

/* Anchored to Europe/Zurich, not the visitor's own browser timezone. */
function lsZurichNowKey() {
  return new Date().toLocaleString('sv-SE', { timeZone: 'Europe/Zurich', hour12: false }).slice(0, 16);
}

function lsFixtureDateKey(dateStr) {
  var m = /^(\d{1,2})\.(\d{1,2})\.(\d{4})/.exec(String(dateStr || '').trim());
  if (!m) return null;
  return m[3] + '-' + lsPad(m[2]) + '-' + lsPad(m[1]);
}

function lsFixtureDateTimeKey(dateStr, timeStr) {
  var dateKey = lsFixtureDateKey(dateStr);
  if (!dateKey) return null;
  var t = (timeStr && /^\d{1,2}:\d{2}/.test(String(timeStr).trim())) ? timeStr.trim() : '00:00';
  return dateKey + ' ' + t;
}

function lsHasKickedOff(dateStr, timeStr) {
  var key = lsFixtureDateTimeKey(dateStr, timeStr);
  return !!key && lsZurichNowKey() >= key;
}

function lsFindNextMatch(fixtures) {
  var todayKey = lsZurichNowKey().slice(0, 10);
  var upcoming = (fixtures || [])
    .filter(function (f) { return /\buzwil\b/i.test(f.home) || /\buzwil\b/i.test(f.away); })
    .map(function (f) { return { f: f, key: lsFixtureDateKey(f.date) }; })
    .filter(function (x) { return x.key && x.key >= todayKey; });
  upcoming.sort(function (a, b) { return a.key < b.key ? -1 : (a.key > b.key ? 1 : 0); });
  return upcoming.length ? upcoming[0].f : null;
}

function lsLoadSavedSquads() {
  return fetch(window.NKCU_DATA + 'live_squad.json?_=' + Date.now())
    .then(function (r) { return r.ok ? r.json() : { matches: {} }; })
    .catch(function () { return { matches: {} }; })
    .then(function (data) {
      var matches = (data && data.matches) || {};
      // A PHP-encoded empty associative array serializes as JSON "[]"
      // instead of "{}" — treat that the same as no saved matches.
      return Array.isArray(matches) ? {} : matches;
    });
}

/* Strikers stand closer together than a full back/mid line, and a
   back three (3-5-2) stands a bit closer than a back four — a
   narrower left/right band keeps them from spreading out to the
   touchlines. Keyed by line + player count, since DEF is 4-wide
   in 4-4-2 but only 3-wide (with wing-backs covering the flanks
   in MID instead) in 3-5-2. */
var LINE_SPREAD = {
  GK: { 1: [50, 50] },
  DEF: { 3: [18, 82], 4: [12, 88] },
  WB: { 2: [12, 88] },
  MID: { 3: [22, 78], 4: [12, 88] },
  ATT: { 2: [34, 66], 3: [22, 78] }
};

function lsRenderToken(name, formation, line, index, total) {
  var spread = (LINE_SPREAD[line] && LINE_SPREAD[line][total]) || [12, 88];
  var x = total <= 1 ? 50 : spread[0] + index * ((spread[1] - spread[0]) / (total - 1));
  var slug = PHOTO_SLUG.hasOwnProperty(name) ? PHOTO_SLUG[name] : undefined;
  var visual = slug
    ? '<img src="assets/img/players/squad/' + slug + '.png" alt="' + lsEsc(name) + '" />'
    : JERSEY_SVG;
  return '<div class="lsp-token" style="left:' + x + '%; top:' + lsLineY(formation, line) + '%;">' +
    '<div class="lsp-visual">' + visual + '</div>' +
    '<span class="lsp-name">' + lsEsc(name) + '</span></div>';
}

function lsRenderPitch(entry, pitchId, emptyId) {
  var pitch = document.getElementById(pitchId || 'liveSquadPitch');
  var empty = document.getElementById(emptyId || 'liveSquadEmpty');
  var formation = FORMATIONS[entry && entry.formation] ? entry.formation : '4-4-2';
  var slots = FORMATIONS[formation];
  var lineup = (entry && entry.lineup) || {};
  var html = '';
  var hasAny = false;

  ['GK', 'DEF', 'MID', 'ATT'].forEach(function (line) {
    var lineSlots = slots[line];
    var groups = {};
    var order = [];
    lineSlots.forEach(function (slot) {
      var eff = lsEffectiveLine(formation, line, slot);
      if (!groups[eff]) { groups[eff] = []; order.push(eff); }
      groups[eff].push(slot);
    });
    order.forEach(function (eff) {
      var group = groups[eff];
      group.forEach(function (slot, i) {
        var name = lineup[slot];
        if (!name) return;
        hasAny = true;
        html += lsRenderToken(name, formation, eff, i, group.length);
      });
    });
  });

  if (hasAny) {
    pitch.innerHTML = html;
    pitch.classList.remove('hidden');
    empty.classList.remove('show');
  } else {
    pitch.innerHTML = '';
    pitch.classList.add('hidden');
    empty.classList.add('show');
  }
}

function lsRenderCard(fixture, entry, ids) {
  ids = ids || {};
  var badgeId = ids.badge || 'lsqBadge';
  var dateId = ids.date || 'lsqDate';
  var onelineId = ids.oneline || 'lsqOneline';
  var footId = ids.foot || 'lsqFoot';
  var scorersId = ids.scorers || 'liveSquadScorers';

  document.getElementById(badgeId).textContent = fixture.league || '';
  document.getElementById(dateId).textContent = fixture.date || '';

  var goals = (entry && entry.goals) || [];
  // Same rule as the homepage next-match card: only show the score
  // once the match has actually started, not just because a stale
  // homeScore:0 is still sitting on the entry.
  var hasScore = lsHasKickedOff(fixture.date, fixture.time) ||
    (entry && (entry.live || goals.length > 0));
  // Score from the live entry; a match the admin app never tracked keeps
  // its result in spiele.json (fixture.homeScore), so fall back to that.
  function lsScore(side) {
    var v = entry && entry[side];
    if (v === null || v === undefined) v = fixture[side];
    return (v === null || v === undefined || v === '') ? 0 : v;
  }
  var scoreOrTime = hasScore
    ? (lsEsc(lsScore('homeScore')) + ' – ' + lsEsc(lsScore('awayScore')))
    : lsEsc(fixture.time || '–');

  document.getElementById(onelineId).innerHTML =
    lsTeamHtml(fixture.home, false) +
    '<span class="mol-score">' + scoreOrTime + '</span>' +
    lsTeamHtml(fixture.away, true);

  var foot = document.getElementById(footId);
  foot.innerHTML = fixture.venue ? '<span>📍 ' + lsEsc(fixture.venue) + '</span>' : '';

  var usIsHome = lsIsUs(fixture.home);
  var sorted = goals.slice().sort(function (a, b) { return (a.minute || 0) - (b.minute || 0); });
  var runningHome = 0;
  var runningAway = 0;
  document.getElementById(scorersId).innerHTML = sorted.map(function (g) {
    var isUsGoal = (g.side === 'home') === usIsHome;
    if (g.side === 'home') { runningHome++; } else { runningAway++; }
    var crestTeam = g.side === 'home' ? fixture.home : fixture.away;
    return '<div class="lsc-row' + (isUsGoal ? '' : ' lsc-opp') + '">' +
      '<span class="lsc-minute">' + lsEsc(g.minute) + '\'</span>' +
      '<span class="lsc-ball">⚽</span>' +
      '<img class="lsc-crest" src="' + lsTeamLogoSrc(crestTeam) + '" alt="" onerror="this.style.display=\'none\'" />' +
      '<span class="lsc-name">' + lsEsc(g.scorer) + '</span>' +
      '<span class="lsc-result">(' + runningHome + ' – ' + runningAway + ')</span>' +
      '</div>';
  }).join('');
}
