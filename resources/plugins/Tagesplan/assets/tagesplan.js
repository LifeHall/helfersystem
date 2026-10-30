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

  // Cells (or handover parts) of a shift, optionally only of one angel type or within one table row
  const shiftCells = (shiftId, row = document, typeId = null) =>
    [...row.querySelectorAll(`.tagesplan [data-tp-shift="${shiftId}"]${typeId ? `[data-tp-type="${typeId}"]` : ''}`)]
      .map((a) => a.closest('.tp-part, td'));

  // Mark the sides where a cell joins a cell of the same shift, the frame is drawn around both together
  const markJoins = () => {
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
  };

  // Change the day directly on selection
  const initDaySelect = () => {
    const daySelect = document.getElementById('tagesplan-day');
    if (daySelect) {
      daySelect.form.querySelector('.tp-day-submit')?.closest('.col-auto')?.classList.add('d-none');
      daySelect.addEventListener('change', () => daySelect.form.submit());
    }
  };

  markJoins();
  initDaySelect();

  // Day plan URL without the popup to re-open
  const pageUrl = () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('shift');
    url.searchParams.delete('type');
    return url;
  };

  // Reload the day plan in place (keeps the scroll position), it also shows the messages
  const refreshPage = () =>
    fetch(pageUrl(), { credentials: 'same-origin' })
      .then((response) => response.text())
      .then((html) => {
        const page = new DOMParser().parseFromString(html, 'text/html').getElementById('tp-page');
        if (page) {
          // The tools keep their event handlers (confirmation of the core)
          const tools = document.querySelector('#tp-page .tp-tools');
          document.getElementById('tp-page').innerHTML = page.innerHTML;
          if (tools) {
            document.querySelector('#tp-page .tp-tools')?.replaceWith(tools);
          }
          markJoins();
          initDaySelect();
        }
      })
      .catch(() => window.location.assign(pageUrl()));

  const highlight = (shiftId, typeId, clicked) => {
    highlighted = shiftCells(shiftId, document, typeId);
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

  const load = (shiftId, typeId) => {
    body.innerHTML = '<div class="text-center p-3"><div class="spinner-border" role="status"></div></div>';
    const params = new URLSearchParams({ shift_id: shiftId, angel_type_id: typeId ?? '' });
    fetch(`${modal.dataset.url}?${params}`, { credentials: 'same-origin' })
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
  let currentType = null;

  modal.addEventListener('show.bs.modal', (event) => {
    const shiftId = event.relatedTarget?.dataset.tpShift ?? modal.dataset.openShift;
    if (!shiftId) {
      return;
    }
    currentShift = shiftId;
    currentType = event.relatedTarget?.dataset.tpType ?? modal.dataset.openType ?? null;
    title.textContent = modal.dataset.title;
    highlight(shiftId, currentType, event.relatedTarget);
    load(shiftId, currentType);
  });

  // Show the other angel types of the shift
  body.addEventListener('click', (event) => {
    const button = event.target.closest('.tp-expand');
    if (!button) {
      return;
    }
    body.querySelectorAll('.tp-type.d-none').forEach((type) => type.classList.remove('d-none'));
    button.remove();
  });

  // Forms marked with .tp-ajax are sent in the background, then the popup content is reloaded.
  // Forms marked with .tp-close close the popup on success and refresh the day plan
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
        // 409: not allowed, the popup shows the reason
        if (!response.ok && response.status !== 409) {
          throw new Error(String(response.status));
        }
        if (response.ok && form.classList.contains('tp-close')) {
          bootstrap.Modal.getOrCreateInstance(modal).hide();
          refreshPage();
          return;
        }
        load(currentShift, currentType);
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
    delete modal.dataset.openType;
  });

  // Re-open the popup after signing up / off (without JavaScript requests)
  if (modal.dataset.openShift) {
    // Reloading the page should not open it again
    window.history.replaceState(null, '', pageUrl());
    const type = modal.dataset.openType ? `[data-tp-type="${modal.dataset.openType}"]` : '';
    const cell = document.querySelector(`[data-tp-shift="${modal.dataset.openShift}"]${type}`)
      ?? document.querySelector(`[data-tp-shift="${modal.dataset.openShift}"]`);
    cell?.scrollIntoView({ block: 'center' });
    bootstrap.Modal.getOrCreateInstance(modal).show(cell);
  }
})();
