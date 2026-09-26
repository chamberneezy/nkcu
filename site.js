// GA4 tracking — shared across every page of this site so there's one place to update the
// Measurement ID or the default parameters, instead of duplicating the snippet per page.
//
// Named site.js, not analytics.js: many ad-block filter lists block ANY file literally named
// analytics.js by filename alone, regardless of domain — confirmed on the live testing branch
// (window.dataLayer never even got created for blocked visitors). Renaming is the actual fix.
//
// This is the `redesign` branch — not yet published to the live site. Same GA4 property/
// Measurement ID as the current live site (this is the same "website" surface, just its next
// version), so once this branch goes live it picks up right where the current site's data left
// off instead of starting a new, disconnected stream.
//
// Part of the same GA4 property as the club's iOS/Android apps (see the internal
// "GA4 Tracking Proposal — NKCU" doc, gitignored at docs/tracking-proposal.html) — app_name and
// platform are what keeps this site's events distinguishable from those apps in reports.
var GA_MEASUREMENT_ID = 'G-SBLCLYPJ1C';

// A page sets this before loading this script to override the default app_name, e.g. admin.html:
//   <script>window.NKCU_APP_NAME = 'admin_app';</script>
var appName = window.NKCU_APP_NAME || 'website';

window.dataLayer = window.dataLayer || [];
// Google's own base gtag.js snippet does `dataLayer.push(arguments)` — functionally fine (gtag.js
// reads it positionally either way), but an `arguments` object serializes to `{"0":...,"1":...}`
// instead of a real array, which looks broken when someone inspects `dataLayer` directly. Pushing
// a real array instead keeps identical behavior and reads as `["event","view_match",{...}]`.
function gtag() { dataLayer.push(Array.prototype.slice.call(arguments)); }

gtag('js', new Date());
gtag('set', 'app_name', appName);
gtag('set', 'platform', 'web');
gtag('config', GA_MEASUREMENT_ID);

(function () {
  var script = document.createElement('script');
  script.async = true;
  script.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_MEASUREMENT_ID;
  document.head.appendChild(script);
})();
