document.querySelectorAll('[data-address-picker]').forEach((picker) => {
    const province = picker.querySelector('[name="province_code"]');
    const city = picker.querySelector('[name="city_code"]');
    const barangay = picker.querySelector('[name="barangay_code"]');
    const status = picker.querySelector('[data-address-status]');
    const retry = picker.querySelector('[data-address-retry]');

    let version = 0;

    function reset(select, label) {
        select.replaceChildren(new Option(label, ''));
        select.disabled = true;
    }

    async function load(
        select,
        level,
        parent,
        label,
        selected,
        request
    ) {
        reset(select, 'Loading…');

        const query = new URLSearchParams({ level, parent });

        const response = await fetch(
            'address-options.php?' + query.toString(),
            {
                headers: {
                    Accept: 'application/json'
                },
                cache: 'no-store'
            }
        );

        if (!response.ok) {
            throw new Error('Address options could not be loaded.');
        }

        const rows = await response.json();

        if (!Array.isArray(rows) || rows.length === 0) {
            throw new Error('No address options returned.');
        }

        if (request !== version) {
            return false;
        }

        select.replaceChildren(new Option('Choose ' + label, ''));

        rows.forEach((row) => {
            select.add(new Option(row.name, row.code));
        });

        select.disabled = false;

        if (
            selected &&
            rows.some((row) => row.code === selected)
        ) {
            select.value = selected;
        }

        return true;
    }

    async function update(stage, initial = false) {
        const request = ++version;

        status.hidden = true;
        retry.hidden = true;
        status.textContent = '';

        try {
            if (stage === 0) {
                reset(city, 'Choose a province first');
                reset(barangay, 'Choose a municipality/city first');

                const loaded = await load(
                    province,
                    'provinces',
                    '',
                    'province',
                    initial ? province.dataset.selected : '',
                    request
                );

                if (!loaded) return;
            }

            if (stage <= 1) {
                reset(city, 'Choose a province first');
                reset(barangay, 'Choose a municipality/city first');

                if (province.value) {
                    const loaded = await load(
                        city,
                        'cities',
                        province.value,
                        'municipality/city',
                        initial ? city.dataset.selected : '',
                        request
                    );

                    if (!loaded) return;
                }
            }

            if (stage <= 2) {
                reset(barangay, 'Choose a municipality/city first');

                if (city.value) {
                    const loaded = await load(
                        barangay,
                        'barangays',
                        city.value,
                        'barangay',
                        initial ? barangay.dataset.selected : '',
                        request
                    );

                    if (!loaded) return;
                }
            }

            if (request === version) {
                status.textContent = '';
                status.hidden = true;
                retry.hidden = true;
            }
        } catch (error) {
            if (request === version) {
                status.hidden = false;
                retry.hidden = false;
                status.textContent =
                    'Unable to load addresses. Select Retry address loading.';
            }
        }
    }

    province.addEventListener('change', () => update(1));
    city.addEventListener('change', () => update(2));

    retry.addEventListener('click', () => {
        province.dataset.selected =
            province.value || province.dataset.selected;

        city.dataset.selected =
            city.value || city.dataset.selected;

        barangay.dataset.selected =
            barangay.value || barangay.dataset.selected;

        update(0, true);
    });

    picker.closest('form').addEventListener('submit', (event) => {
        const incomplete = [province, city, barangay].some(
            (select) => select.disabled || !select.value
        );

        if (incomplete) {
            event.preventDefault();

            status.hidden = false;
            status.textContent =
                'Select all three address locations before saving.';
        }
    });

    update(0, true);
});