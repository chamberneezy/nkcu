// GA4 tracking — shared across every page of this site so there's one place to update the
// Measurement ID or the default parameters, instead of duplicating the snippet per page.
//
// Part of the same GA4 property as the club's iOS/Android apps (see the internal
// "GA4 Tracking Proposal — NKCU" doc, gitignored at docs/tracking-proposal.html) — app_name and
// platform are what keeps this site's events distinguishable from those apps in reports.
//
// Web data stream for this property (GA4 Admin -> Data Streams -> Web -> NK Croatia Uzwil).
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
