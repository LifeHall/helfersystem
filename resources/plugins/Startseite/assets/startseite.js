// Guided sign up of the start page
(() => {
    // Step 2: days, "all days" ticks or unticks all days that are not locked
    const daysForm = document.getElementById('startseite-days');
    if (daysForm) {
        const days = [...daysForm.querySelectorAll('.startseite-day')];
        const all = document.getElementById('startseite-all');
        const update = () => {
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

    // Step 3 of the automatic assignment: "all times" ticks or unticks all shift times
    const autoForm = document.getElementById('startseite-auto');
    if (autoForm) {
        const times = [...autoForm.querySelectorAll('.startseite-auto-time')];
        const all = document.getElementById('startseite-auto-all');
        const update = () => {
            all.checked = times.length > 0 && times.every((time) => time.checked);
        };
        times.forEach((time) => time.addEventListener('change', update));
        all.addEventListener('change', () => {
            times.forEach((time) => {
                time.checked = all.checked;
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
        // Same rules as the server: overlapping shifts collide, except a handover at the same location
        // with the same helper type (not exactly the same time); coordinators of a helper type may sign up
        // for parallel shifts; only one helper type per shift
        const slot = (box) => ({
            shift: box.dataset.shift,
            type: box.dataset.type,
            location: box.dataset.location,
            start: Number(box.dataset.start),
            end: Number(box.dataset.end),
            manage: box.dataset.manage === '1',
        });
        const collides = (candidate, ticked) => {
            if (candidate.shift === ticked.shift) {
                return true;
            }
            if (candidate.manage || candidate.start >= ticked.end || candidate.end <= ticked.start) {
                return false;
            }
            return !(
                candidate.location === ticked.location
                && candidate.type === ticked.type
                && !(candidate.start === ticked.start && candidate.end === ticked.end)
            );
        };

        // Disabled by the server (full, not allowed, ...) stays disabled
        const free = boxes.filter((box) => !box.disabled);
        const updateBlocked = () => {
            const ticked = boxes.filter((box) => box.checked).map((box) => ({ box, slot: slot(box) }));
            free.forEach((box) => {
                const candidate = slot(box);
                const blocked = !box.checked
                    && ticked.some((other) => other.box !== box && collides(candidate, other.slot));
                box.disabled = blocked;
                const label = box.closest('label');
                label.classList.toggle('startseite-blocked', blocked);
                if (blocked) {
                    label.title = tableForm.dataset.blocked;
                } else {
                    label.removeAttribute('title');
                }
            });
            tableForm.querySelectorAll('.startseite-slots td').forEach((cell) => {
                const cellBoxes = [...cell.querySelectorAll('input[name="slots[]"]')];
                cell.classList.toggle(
                    'startseite-blocked-all',
                    cellBoxes.length > 0 && cellBoxes.every((box) => box.disabled && !box.checked)
                );
            });
        };

        boxes.forEach((box) => box.addEventListener('change', () => {
            updateBlocked();
            updateChanges();
        }));
        updateBlocked();
        updateChanges();
    }
})();
