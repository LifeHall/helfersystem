/**
 * Tagesplan: loads the shift popup content and highlights the clicked cell
 */
(() => {
  const modal = document.getElementById('tp-modal');
  if (!modal) {
    return;
  }

  const body = modal.querySelector('.modal-body');
  const title = modal.querySelector('.modal-title');
  let highlighted = [];

  const highlight = (shiftId, clicked) => {
    highlighted = [...document.querySelectorAll(`[data-tp-shift="${shiftId}"]`)].map((a) => a.closest('td'));
    highlighted.forEach((td) => td.classList.add('tp-active'));
    clicked?.closest('td')?.classList.add('tp-clicked');
  };

  const load = (shiftId) => {
    body.innerHTML = '<div class="text-center p-3"><div class="spinner-border" role="status"></div></div>';
    fetch(`${modal.dataset.url}?shift_id=${encodeURIComponent(shiftId)}`, { credentials: 'same-origin' })
      .then((response) => {
        if (!response.ok) {
          throw new Error(String(response.status));
        }
        return response.text();
      })
      .then((html) => {
        body.innerHTML = html;
        const content = body.querySelector('.tp-shift');
        if (content) {
          title.textContent = content.dataset.title;
        }
      })
      .catch(() => {
        body.innerHTML = `<div class="alert alert-danger">${modal.dataset.error}</div>`;
      });
  };

  modal.addEventListener('show.bs.modal', (event) => {
    const shiftId = event.relatedTarget?.dataset.tpShift ?? modal.dataset.openShift;
    if (!shiftId) {
      return;
    }
    title.textContent = modal.dataset.title;
    highlight(shiftId, event.relatedTarget);
    load(shiftId);
  });

  modal.addEventListener('hidden.bs.modal', () => {
    highlighted.forEach((td) => td.classList.remove('tp-active', 'tp-clicked'));
    highlighted = [];
    delete modal.dataset.openShift;
  });

  // Re-open the popup after signing up / off
  if (modal.dataset.openShift) {
    const cell = document.querySelector(`[data-tp-shift="${modal.dataset.openShift}"]`);
    cell?.scrollIntoView({ block: 'center' });
    bootstrap.Modal.getOrCreateInstance(modal).show(cell);
  }
})();
