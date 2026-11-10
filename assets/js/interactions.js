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
      const response = await fetch('/vote-tool-ajax', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
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

  const toolLink = event.target.closest('a[href*="redirect.php?id="]');
  if (toolLink) {
    const currentHref = toolLink.getAttribute('href') || '';
    if (currentHref.includes('tracked=1')) return;

    event.preventDefault();

    const url = new URL(currentHref, window.location.href);
    const toolId = url.searchParams.get('id');
    if (!toolId) return;

    const card = toolLink.closest('.card, .tool-card, .toolCard');
    const clickCount = card ? card.querySelector('.js-click-count') : null;

    const body = new URLSearchParams();
    body.set('id', toolId);

    try {
      const response = await fetch('track-click.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString(),
        keepalive: true
      });

      const text = await response.text();

      if (response.ok) {
        try {
          const data = JSON.parse(text);
          if (data.success && clickCount) {
            clickCount.textContent = data.clicks ?? 0;
          }
        } catch (e) {
          console.error('Invalid JSON from track-click.php:', text);
        }
      } else {
        console.error('Track click HTTP error:', response.status, text);
      }
    } catch (error) {
      console.error(error);
    }

    url.searchParams.set('tracked', '1');

    if (toolLink.getAttribute('target') === '_blank') {
      window.open(url.toString(), '_blank', 'noopener');
    } else {
      window.location.href = url.toString();
    }
  }
});