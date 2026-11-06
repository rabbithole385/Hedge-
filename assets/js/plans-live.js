/* Live price refresh for market-linked plan pages. */
(function () {
  var nodes = document.querySelectorAll('[data-key]');
  if (!nodes.length) return;
  var keys = {};
  nodes.forEach(function (n) { keys[n.getAttribute('data-key')] = true; });
  var list = Object.keys(keys).join(',');
  var last = {};

  function cls(c) { if (c === null || Math.abs(c) < 0.005) return 'flat'; return c > 0 ? 'up' : 'down'; }

  function apply(quotes) {
    document.querySelectorAll('[data-key]').forEach(function (row) {
      var q = quotes[row.getAttribute('data-key')];
      if (!q) return;
      var px = row.querySelector('[data-px]');
      var ch = row.querySelector('[data-chg]');
      if (px && px.textContent !== q.price_fmt) {
        var prev = last[row.getAttribute('data-key')];
        px.textContent = q.price_fmt;
        if (prev !== undefined && prev !== q.price) {
          px.classList.remove('flash-up', 'flash-down');
          void px.offsetWidth;
          px.classList.add(q.price > prev ? 'flash-up' : 'flash-down');
        }
      }
      if (ch) { ch.textContent = q.change_fmt; ch.className = 'lp-chg ' + cls(q.change_pct); }
    });
    Object.keys(quotes).forEach(function (k) { if (quotes[k]) last[k] = quotes[k].price; });
    document.dispatchEvent(new CustomEvent('lp:quotes', { detail: quotes }));
  }

  function poll() {
    if (document.hidden) return;
    fetch('/api/live_quotes.php?keys=' + encodeURIComponent(list), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.ok) apply(j.quotes); })
      .catch(function () {});
  }
  setInterval(poll, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
