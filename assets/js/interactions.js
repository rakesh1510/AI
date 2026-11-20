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