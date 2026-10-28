// Guided sign up of the start page
(() => {
    // Step 2: days, the times of a day can only be changed if the day is selected
    const daysForm = document.getElementById('startseite-days');
    if (daysForm) {
        const days = [...daysForm.querySelectorAll('.startseite-day')];
        const all = document.getElementById('startseite-all');
        const update = () => {
            days.forEach((day) => {
                // Locked days are sent by a hidden field, their times have to be sent too
                const enabled = day.checked || day.disabled;
                day.closest('tr').querySelectorAll('select').forEach((select) => {
                    select.disabled = !enabled;
                });
            });
            all.checked = days.length > 0 && days.every((day) => day.checked);
        };
        days.forEach((day) => day.addEventListener('change', update));
        all.addEventListener('change', () => {
            days.filter((day) => !day.disabled).forEach((day) => {
                day.checked = all.checked;
            });
            update();
        });
        update();
    }

    // Step 3: table of the time slots
    const tableForm = document.getElementById('startseite-table');
    if (tableForm) {
        const onlyFree = document.getElementById('startseite-only-free');
        const changes = document.getElementById('startseite-changes');
        const boxes = [...tableForm.querySelectorAll('input[name="slots[]"]')];
        const storageKey = 'startseite-only-free';

        const applyFilter = () => {
            tableForm.classList.toggle('startseite-only-free', onlyFree.checked);
            try {
                localStorage.setItem(storageKey, onlyFree.checked ? '1' : '');
            } catch (e) {
                // Storage not available, the filter is not remembered
            }
        };
        if (onlyFree) {
            try {
                onlyFree.checked = localStorage.getItem(storageKey) === '1';
            } catch (e) {
                // Storage not available
            }
            onlyFree.addEventListener('change', applyFilter);
            applyFilter();
        }

        // Mark changed slots and show how many will be added / removed
        const updateChanges = () => {
            let added = 0;
            let removed = 0;
            boxes.forEach((box) => {
                const own = box.dataset.own === '1';
                const changed = box.checked !== own;
                box.closest('td').classList.toggle('startseite-changed', changed);
                if (changed) {
                    own ? removed++ : added++;
                }
            });
            changes.textContent = added || removed
                ? changes.dataset.text.replace('%1$s', added).replace('%2$s', removed)
                : '';
        };
        boxes.forEach((box) => box.addEventListener('change', updateChanges));
        updateChanges();
    }
})();
