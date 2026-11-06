/* Live market list for public market pages: <div class="lv" data-type="crypto" data-title="..."></div> */
(function () {
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function chg(c) { if (c === null || c === undefined) return '<span class="lv-chg flat">&mdash;</span>'; var k = Math.abs(c) < .005 ? 'flat' : (c > 0 ? 'up' : 'down'); return '<span class="lv-chg ' + k + '">' + (c > 0 ? '+' : '') + c.toFixed(2) + '%</span>'; }
  function render(box) {
    var q = box.getAttribute('data-symbols') ? 'symbols=' + encodeURIComponent(box.getAttribute('data-symbols')) : 'type=' + encodeURIComponent(box.getAttribute('data-type') || '');
    fetch('/api/public_markets.php?' + q, { cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      var title = box.getAttribute('data-title') || 'Available now';
      var head = '<div class="lv-head"><b>' + esc(title) + '</b><span class="lv-live"><i class="lv-dot"></i>Live</span></div>';
      if (!j || !j.ok) { box.innerHTML = head + '<div class="lv-empty">Live prices are temporarily unavailable. Please check back shortly.</div>'; return; }
      if (!j.markets.length) { box.innerHTML = head + '<div class="lv-empty">' + esc(box.getAttribute('data-empty') || 'No markets in this category are open for trading right now.') + '</div>'; return; }
      box.innerHTML = head + '<div class="lv-rows">' + j.markets.map(function (m) {
        return '<div class="lv-row"><div class="lv-name">' + m.icon.replace(/width:36px;height:36px/g, 'width:32px;height:32px') + '<div style="min-width:0"><b>' + esc(m.name) + '</b><small>' + esc(m.symbol) + '</small></div></div><div class="lv-px">' + esc(m.price_fmt) + '</div>' + chg(m.change_pct) + '</div>';
      }).join('') + '</div><div class="lv-foot">' + j.markets.length + ' market' + (j.markets.length === 1 ? '' : 's') + ' &middot; prices refresh about once a minute &middot; 24h change</div>';
    }).catch(function () {});
  }
  document.querySelectorAll('.lv').forEach(function (b) { render(b); setInterval(function () { render(b); }, 60000); });
})();
