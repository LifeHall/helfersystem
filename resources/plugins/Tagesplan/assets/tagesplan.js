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

  // Cells (or handover parts) of a shift, optionally only within one table row
  const shiftCells = (shiftId, row = document) =>
    [...row.querySelectorAll(`.tagesplan [data-tp-shift="${shiftId}"]`)].map((a) => a.closest('.tp-part, td'));

  // Mark the sides where a cell joins a cell of the same shift, the frame is drawn around both together
  document.querySelectorAll('.tagesplan [data-tp-shift]').forEach((a) => {
    const td = a.closest('td');
    const cell = a.closest('.tp-part, td');
    const selector = `[data-tp-shift="${a.dataset.tpShift}"]`;
    if (td.previousElementSibling?.querySelector(selector)) {
      cell.classList.add('tp-join-l');
    }
    if (td.nextElementSibling?.querySelector(selector)) {
      cell.classList.add('tp-join-r');
    }
  });

  const highlight = (shiftId, clicked) => {
    highlighted = shiftCells(shiftId);
    highlighted.forEach((cell) => cell.classList.add('tp-active'));
    const row = clicked?.closest('tr');
    if (row) {
      shiftCells(shiftId, row).forEach((cell) => cell.classList.add('tp-clicked'));
    }
  };

  // Hovering a cell highlights the whole shift in this row (including its handover part)
  const hover = (event, add) => {
    const cell = event.target.closest?.('.tagesplan .tp-part, .tagesplan td');
    const a = cell?.querySelector(':scope > [data-tp-shift]');
    if (!a || cell.contains(event.relatedTarget)) {
      return;
    }
    shiftCells(a.dataset.tpShift, a.closest('tr')).forEach((cell) => cell.classList.toggle('tp-hover', add));
  };
  document.addEventListener('mouseover', (event) => hover(event, true));
  document.addEventListener('mouseout', (event) => hover(event, false));

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

  let currentShift = null;

  modal.addEventListener('show.bs.modal', (event) => {
    const shiftId = event.relatedTarget?.dataset.tpShift ?? modal.dataset.openShift;
    if (!shiftId) {
      return;
    }
    currentShift = shiftId;
    title.textContent = modal.dataset.title;
    highlight(shiftId, event.relatedTarget);
    load(shiftId);
  });

  // Forms marked with .tp-ajax are sent in the background, then only the popup content is reloaded
  body.addEventListener('submit', (event) => {
    const form = event.target.closest('form.tp-ajax');
    if (!form) {
      return;
    }
    event.preventDefault();
    form.querySelectorAll('button').forEach((button) => {
      button.disabled = true;
    });
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Tagesplan': '1' },
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(String(response.status));
        }
        load(currentShift);
      })
      .catch(() => {
        body.innerHTML = `<div class="alert alert-danger">${modal.dataset.error}</div>`;
      });
  });

  modal.addEventListener('hidden.bs.modal', () => {
    // Bootstrap focuses the clicked cell again, which would keep its hover text visible
    if (document.activeElement?.closest('.tagesplan')) {
      document.activeElement.blur();
    }
    highlighted.forEach((cell) => cell.classList.remove('tp-active', 'tp-clicked'));
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
