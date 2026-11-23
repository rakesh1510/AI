function csrfToken() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }

document.addEventListener('click', async function (event) {
  const voteBtn = event.target.closest('.js-vote-btn');
  if (voteBtn) {
    event.preventDefault();

    const box = voteBtn.closest('.toolStatsAjax');
    if (!box) return;

    const toolId = voteBtn.getAttribute('data-tool-id') || box.getAttribute('data-tool-id');
    const vote = voteBtn.getAttribute('data-vote');

    const body = new URLSearchParams();
    body.set('id', toolId);
    body.set('type', vote);

    try {
      const response = await fetch('/vote-tool-ajax.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrfToken(),
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString()
      });

      const text = await response.text();

      if (!response.ok) {
        console.error('Vote HTTP error:', response.status, text);
        alert('Vote request failed');
        return;
      }

      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        console.error('Invalid JSON:', text);
        alert('Invalid server response');
        return;
      }

      if (!data.success) {
        alert(data.message || 'Could not save vote');
        return;
      }

      const likeCount = box.querySelector('.js-like-count');
      const dislikeCount = box.querySelector('.js-dislike-count');
      const clickCount = box.querySelector('.js-click-count');

      if (likeCount) likeCount.textContent = data.likes ?? 0;
      if (dislikeCount) dislikeCount.textContent = data.dislikes ?? 0;
      if (clickCount) clickCount.textContent = data.clicks ?? 0;

      box.querySelectorAll('.js-vote-btn').forEach(btn => btn.classList.remove('active'));
      voteBtn.classList.add('active');
    } catch (error) {
      console.error(error);
      alert('Vote request failed');
    }

    return;
  }

  // Outbound tool links are counted ONCE, on the server, by redirect.php. This script no longer intercepts them (a second count here double-counted every click).
  // (Analytics A1.) The visible click counter refreshes on the next page load.
});

/* Click counter refresh (Analytics: public 👁 total). The number shown is ALWAYS the server's total: this code never adds 1 by itself.
   After a visitor follows a "Visit site" link (opened in a new tab) and comes back, or returns with the Back button (page restored from cache), the totals of the tools on this page are re-read
   from the read-only /click-count.php endpoint and written into the counters. Suppressed clicks (duplicates, bots, prefetches) are not counted by the server, so they never change the number. */
(function () {
  'use strict';
  var pending = Object.create(null), inflight = false, timer = null;
  function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.toolStatsAjax[data-tool-id]')); }
  function allIds() { var o = {}; boxes().forEach(function (b) { o[b.getAttribute('data-tool-id')] = 1; }); return Object.keys(o); }
  function apply(counts) {
    boxes().forEach(function (b) {
      var id = b.getAttribute('data-tool-id'), n = counts && counts[id], el = b.querySelector('.js-click-count');
      if (el && typeof n === 'number' && isFinite(n) && n >= 0 && Math.floor(n) === n) el.textContent = String(n);
    });
  }
  function refresh(ids) {
    ids = (ids || []).filter(Boolean).slice(0, 30); if (!ids.length || inflight || !window.fetch) return; inflight = true;
    fetch('/click-count.php?ids=' + encodeURIComponent(ids.join(',')), { cache: 'no-store', credentials: 'omit', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.counts) { apply(d.counts); ids.forEach(function (i) { delete pending[i]; }); } })
      .catch(function () { /* the server-rendered number stays; nothing is guessed */ })
      .then(function () { inflight = false; });
  }
  function later(ids) { clearTimeout(timer); timer = setTimeout(function () { refresh(ids || Object.keys(pending)); }, 500); }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href*="redirect.php?id="]') : null; if (!a) return;
    var m = /[?&]id=([^&]+)/.exec(a.getAttribute('href') || ''); if (m) { try { pending[decodeURIComponent(m[1])] = 1; } catch (x) { /* ignore */ } }
  }, true);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && Object.keys(pending).length) later(); });
  window.addEventListener('focus', function () { if (Object.keys(pending).length) later(); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) later(allIds()); else if (Object.keys(pending).length) later(); });   // Back button / page restored from the browser cache
  try { var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0]; if (nav && nav.type === 'back_forward') later(allIds()); } catch (e) { /* ignore */ }
})();
