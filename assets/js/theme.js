/* Applies the theme before first paint (loaded synchronously in <head>; no inline scripts because of CSP). */
(function () {
  var el = document.documentElement;
  var pref = el.getAttribute('data-theme') || 'system';
  var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  el.classList.toggle('dark', dark);
})();
