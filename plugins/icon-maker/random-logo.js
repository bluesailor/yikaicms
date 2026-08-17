(() => {
    'use strict';

    const root = document.querySelector('.im-random-lab');
    if (!root) return;

    const byId = id => document.getElementById(id);
    const form = byId('imRandomForm');
    const navButtons = [...root.querySelectorAll('[data-im-control-target]')];
    const sections = navButtons.map(button => byId(button.dataset.imControlTarget)).filter(Boolean);
    const colorInputs = ['imCustomColor1', 'imCustomColor2', 'imCustomColor3'].map(byId).filter(Boolean);
    const customScheme = form.querySelector('input[name="scheme"][value="custom"]');
    const customMono = form.querySelector('input[name="mono_color"][value="custom"]');
    const picker = byId('imMonoPicker');
    const pickerState = {h: 0, s: 1, v: 1};
    let refreshTimer = 0;

    const candidateGrid = root.querySelector('[data-im-random-candidates]');
    const remoteButton = byId('imRemoteGenerate');
    const remoteStatus = byId('imRemoteStatus');
    const apiSaveButton = byId('imLogoApiSave');
    const apiStatus = byId('imLogoApiStatus');
    let remoteObjectUrls = [];
    const candidateOrder = window.IconMakerRandomOrder;
    const candidateOrderKey = candidateOrder?.key(window.location.search) || '';
    const candidateIds = () => [...(candidateGrid?.querySelectorAll('[data-im-random-candidate]') || [])]
        .map(card => card.dataset.imRandomCandidate);
    const saveCandidateOrder = () => {
        if (!candidateOrderKey) return;
        try { sessionStorage.setItem(candidateOrderKey, JSON.stringify(candidateIds())); } catch (_) {}
    };
    const restoreCandidateOrder = () => {
        if (!candidateGrid || !candidateOrder || !candidateOrderKey) return;
        let saved = [];
        try { saved = JSON.parse(sessionStorage.getItem(candidateOrderKey) || '[]'); } catch (_) {}
        const cards = new Map([...candidateGrid.querySelectorAll('[data-im-random-candidate]')]
            .map(card => [card.dataset.imRandomCandidate, card]));
        candidateOrder.normalize([...cards.keys()], saved).forEach(id => candidateGrid.appendChild(cards.get(id)));
    };
    const announceCandidateOrder = card => {
        const status = root.querySelector('[data-im-order-status]');
        if (!status || !card) return;
        const position = candidateIds().indexOf(card.dataset.imRandomCandidate) + 1;
        status.textContent = String(candidateGrid.dataset.imOrderMessage || ':n · :position')
            .replace(':n', String(Number(card.dataset.imRandomCandidate) + 1))
            .replace(':position', String(position));
    };

    restoreCandidateOrder();
    if (candidateGrid && typeof window.Sortable !== 'undefined') {
        new window.Sortable(candidateGrid, {
            animation: 160,
            draggable: '[data-im-random-candidate]',
            handle: '.im-random-drag-handle',
            ghostClass: 'im-random-sort-ghost',
            chosenClass: 'im-random-sort-chosen',
            dragClass: 'im-random-sort-drag',
            onEnd: event => {
                saveCandidateOrder();
                announceCandidateOrder(event.item);
            }
        });
    }

    candidateGrid?.querySelectorAll('.im-random-drag-handle').forEach(handle => {
        handle.addEventListener('keydown', event => {
            if (!candidateOrder || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const card = handle.closest('[data-im-random-candidate]');
            const order = candidateIds();
            const current = order.indexOf(card.dataset.imRandomCandidate);
            const columns = Math.max(1, getComputedStyle(candidateGrid).gridTemplateColumns.split(' ').length);
            const target = event.key === 'Home' ? 0
                : event.key === 'End' ? order.length - 1
                : current + ({ArrowLeft: -1, ArrowRight: 1, ArrowUp: -columns, ArrowDown: columns}[event.key] || 0);
            const next = candidateOrder.move(order, card.dataset.imRandomCandidate, target);
            const cards = new Map([...candidateGrid.querySelectorAll('[data-im-random-candidate]')]
                .map(item => [item.dataset.imRandomCandidate, item]));
            next.forEach(id => candidateGrid.appendChild(cards.get(id)));
            saveCandidateOrder();
            announceCandidateOrder(card);
            handle.focus();
        });
    });

    const checkedValue = name => form.querySelector('input[name="' + name + '"]:checked')?.value || '';
    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            const section = byId(button.dataset.imControlTarget);
            const closing = section && !section.hidden;
            sections.forEach(item => item.hidden = true);
            navButtons.forEach(item => {
                item.classList.remove('active');
                item.setAttribute('aria-expanded', 'false');
            });
            if (!section || closing) return;
            section.hidden = false;
            button.classList.add('active');
            button.setAttribute('aria-expanded', 'true');
        });
    });

    root.querySelectorAll('.im-random-option input').forEach(input => {
        input.addEventListener('change', () => {
            const summary = input.closest('.im-random-expand')?.querySelector('.im-random-summary');
            if (summary) summary.textContent = input.dataset.tip || '';
        });
    });

    const updateCandidateColors = () => {
        const values = {
            color_mode: checkedValue('color_mode') || 'trio',
            scheme: checkedValue('scheme') || 'industry',
            mono_color: checkedValue('mono_color') || 'industry'
        };
        colorInputs.forEach((input, index) => values['custom_color' + (index + 1)] = input.value);

        root.querySelectorAll('[data-im-random-candidate]').forEach(card => {
            if (card.dataset.imRemote === '1') return;
            const image = card.querySelector('.im-random-preview img');
            if (!image) return;
            const url = new URL(image.getAttribute('src'), window.location.href);
            Object.entries(values).forEach(([key, value]) => url.searchParams.set(key, value));
            const src = url.pathname + '?' + url.searchParams.toString();
            image.src = src;
            card.querySelectorAll('.im-random-use').forEach(button => button.dataset.src = src);
            const download = card.querySelector('.im-random-actions a');
            if (download) download.href = src;
        });
    };

    const remotePayload = () => {
        const values = Object.fromEntries(new FormData(form).entries());
        return {
            action: 'candidates',
            mark: values.mark || 'YK',
            industry: values.industry || 'technology',
            subindustry: values.subindustry || 'all',
            seed: Number(values.seed || 1),
            scheme: values.scheme || 'industry',
            color_mode: values.color_mode || 'trio',
            mono_color: values.mono_color || 'industry',
            custom_color1: values.custom_color1 || '#1D4ED8',
            custom_color2: values.custom_color2 || '#22D3EE',
            custom_color3: values.custom_color3 || '#0F172A',
            letter_style: values.letter_style || 'abstract',
            effect: values.effect || 'auto',
            background: values.background || 'transparent',
            limit: 12,
            pool_size: 96
        };
    };

    const renderRemoteCandidates = candidates => {
        if (!candidateGrid || !Array.isArray(candidates)) return;
        remoteObjectUrls.forEach(url => URL.revokeObjectURL(url));
        remoteObjectUrls = [];
        [...candidateGrid.querySelectorAll('[data-im-random-candidate]')].forEach((card, index) => {
            const candidate = candidates[index];
            if (!candidate || typeof candidate.svg !== 'string') return;
            const url = URL.createObjectURL(new Blob([candidate.svg], {type: 'image/svg+xml'}));
            remoteObjectUrls.push(url);
            card.dataset.imRemote = '1';
            const image = card.querySelector('.im-random-preview img');
            if (image) {
                image.src = url;
                image.alt = 'LOGO LAB 候选 ' + (index + 1);
            }
            card.querySelectorAll('.im-random-use').forEach(button => button.dataset.src = url);
            const download = card.querySelector('.im-random-actions a');
            if (download) {
                download.href = url;
                download.download = 'logo-lab-' + (index + 1) + '.svg';
            }
            const effectLabel = card.querySelector('.im-random-preview span');
            if (effectLabel) effectLabel.textContent = candidate.traits?.effect || 'LOGO LAB';
        });
    };

    remoteButton?.addEventListener('click', async () => {
        remoteButton.disabled = true;
        remoteStatus.textContent = '正在连接 LOGO LAB…';
        const fd = new FormData();
        fd.set('_token', remoteButton.dataset.token || '');
        fd.set('im_action', 'remote_candidates');
        fd.set('payload', JSON.stringify(remotePayload()));
        try {
            const response = await fetch(location.href, {method: 'POST', body: fd});
            const result = await response.json();
            if (!response.ok || result.code !== 0) throw new Error(result.msg || 'LOGO LAB 请求失败。');
            renderRemoteCandidates(result.data?.candidates || []);
            remoteStatus.textContent = '已使用 LOGO LAB 引擎';
        } catch (error) {
            remoteStatus.textContent = error.message || 'LOGO LAB 请求失败。';
        } finally {
            remoteButton.disabled = false;
        }
    });

    apiSaveButton?.addEventListener('click', async () => {
        apiSaveButton.disabled = true;
        apiStatus.textContent = '正在保存…';
        const fd = new FormData();
        fd.set('_token', apiSaveButton.dataset.token || '');
        fd.set('im_action', 'save_api_config');
        fd.set('api_url', byId('imLogoApiUrl')?.value || '');
        fd.set('api_key', byId('imLogoApiKey')?.value || '');
        try {
            const response = await fetch(location.href, {method: 'POST', body: fd});
            const result = await response.json();
            if (!response.ok || result.code !== 0) throw new Error(result.msg || '保存失败。');
            byId('imLogoApiKey').value = '';
            apiStatus.textContent = '已保存';
        } catch (error) {
            apiStatus.textContent = error.message || '保存失败。';
        } finally {
            apiSaveButton.disabled = false;
        }
    });

    const scheduleRefresh = () => {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(updateCandidateColors, 80);
    };

    const currentMode = () => checkedValue('color_mode') || 'trio';

    const syncColorPanels = () => {
        const mode = currentMode();
        root.querySelectorAll('[data-im-color-panel]').forEach(panel => {
            panel.hidden = panel.dataset.imColorPanel === 'mono' ? mode !== 'mono' : mode === 'mono';
        });
        const schemes = root.querySelector('.im-random-scheme-list');
        if (schemes) schemes.dataset.mode = mode;
        const customControls = byId('imCustomColorControls');
        if (customControls) customControls.hidden = mode === 'mono' || !customScheme?.checked;
        const second = root.querySelector('[data-im-custom-second]');
        if (second) second.hidden = false;
        const third = root.querySelector('[data-im-custom-third]');
        if (third) third.hidden = mode !== 'trio';
        if (mode !== 'mono' && picker?.open) picker.close();
    };

    const syncCustomDot = () => {
        const dot = byId('imCustomSchemeDot');
        if (!dot) return;
        colorInputs.forEach((input, index) => dot.style.setProperty('--im-c' + (index + 1), input.value));
    };

    form.querySelectorAll('input[name="color_mode"]').forEach(input => {
        input.addEventListener('change', () => {
            syncColorPanels();
            updateCandidateColors();
        });
    });
    form.querySelectorAll('input[name="scheme"]').forEach(input => {
        input.addEventListener('change', () => {
            syncColorPanels();
            updateCandidateColors();
        });
    });
    form.querySelectorAll('input[name="mono_color"]').forEach(input => {
        input.addEventListener('change', () => {
            if (input.checked && input.value !== 'industry' && input.value !== 'custom') {
                const color = input.closest('label')?.querySelector('.im-random-mono-dot')?.style.getPropertyValue('--im-mono');
                if (color) setPickerFromHex(color);
            }
            updateCandidateColors();
        });
    });
    colorInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            if (currentMode() === 'mono') {
                if (index === 0) setPickerFromHex(input.value);
                if (customMono) customMono.checked = true;
            } else if (customScheme) {
                customScheme.checked = true;
            }
            syncCustomDot();
            syncColorPanels();
            scheduleRefresh();
        });
    });

    byId('imRandomNewSeed')?.addEventListener('click', () => {
        const values = new Uint32Array(1);
        crypto.getRandomValues(values);
        byId('imRandomSeed').value = String(1 + values[0] % 2147483646);
        form.submit();
    });

    const hsvToHex = (h, s, v) => {
        const hue = ((h % 360) + 360) % 360;
        const chroma = v * s;
        const x = chroma * (1 - Math.abs((hue / 60) % 2 - 1));
        const match = v - chroma;
        let rgb = [chroma, x, 0];
        if (hue >= 60 && hue < 120) rgb = [x, chroma, 0];
        else if (hue >= 120 && hue < 180) rgb = [0, chroma, x];
        else if (hue >= 180 && hue < 240) rgb = [0, x, chroma];
        else if (hue >= 240 && hue < 300) rgb = [x, 0, chroma];
        else if (hue >= 300) rgb = [chroma, 0, x];
        return '#' + rgb.map(channel => Math.round((channel + match) * 255).toString(16).padStart(2, '0')).join('').toUpperCase();
    };

    const hexToHsv = hex => {
        const value = String(hex).trim().replace(/^#/, '');
        if (!/^[0-9a-f]{6}$/i.test(value)) return null;
        const [r, g, b] = [0, 2, 4].map(index => parseInt(value.slice(index, index + 2), 16) / 255);
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const delta = max - min;
        let hue = 0;
        if (delta) {
            if (max === r) hue = 60 * (((g - b) / delta) % 6);
            else if (max === g) hue = 60 * ((b - r) / delta + 2);
            else hue = 60 * ((r - g) / delta + 4);
        }
        return {h: (hue + 360) % 360, s: max ? delta / max : 0, v: max};
    };

    const svPicker = byId('imMonoSvPicker');
    const huePicker = byId('imMonoHuePicker');
    const svMarker = byId('imMonoSvMarker');
    const hueMarker = byId('imMonoHueMarker');
    const pickerPreview = byId('imMonoPickerPreview');
    const triggerSwatch = byId('imMonoPickerTriggerSwatch');
    const triggerValue = byId('imMonoPickerTriggerValue');
    const hexValue = byId('imMonoHexValue');

    function renderPicker() {
        const color = hsvToHex(pickerState.h, pickerState.s, pickerState.v);
        svPicker?.style.setProperty('--im-picker-hue', 'hsl(' + pickerState.h + ' 100% 50%)');
        if (svMarker) {
            svMarker.style.left = (pickerState.s * 100) + '%';
            svMarker.style.top = ((1 - pickerState.v) * 100) + '%';
            svMarker.style.background = color;
        }
        if (hueMarker) hueMarker.style.top = (pickerState.h / 360 * 100) + '%';
        if (huePicker) huePicker.setAttribute('aria-valuenow', String(Math.round(pickerState.h)));
        if (pickerPreview) pickerPreview.style.background = color;
        if (triggerSwatch) triggerSwatch.style.background = color;
        if (triggerValue) triggerValue.textContent = color.slice(1);
        if (hexValue && document.activeElement !== hexValue) hexValue.value = color.slice(1);
        return color;
    }

    function setPickerFromHex(hex) {
        const value = hexToHsv(hex);
        if (!value) return false;
        Object.assign(pickerState, value);
        renderPicker();
        return true;
    }

    const commitPicker = () => {
        if (!colorInputs[0] || !customMono) return;
        colorInputs[0].value = renderPicker();
        customMono.checked = true;
        syncCustomDot();
        scheduleRefresh();
    };

    const updateSv = event => {
        const rect = svPicker.getBoundingClientRect();
        pickerState.s = clamp((event.clientX - rect.left) / rect.width, 0, 1);
        pickerState.v = clamp(1 - (event.clientY - rect.top) / rect.height, 0, 1);
        commitPicker();
    };
    const updateHue = event => {
        const rect = huePicker.getBoundingClientRect();
        pickerState.h = clamp((event.clientY - rect.top) / rect.height, 0, 1) * 359.999;
        commitPicker();
    };

    const addPointerDrag = (element, update) => {
        if (!element) return;
        let dragging = false;
        element.addEventListener('pointerdown', event => {
            dragging = true;
            element.setPointerCapture(event.pointerId);
            update(event);
        });
        element.addEventListener('pointermove', event => {
            if (dragging) update(event);
        });
        element.addEventListener('pointerup', event => {
            dragging = false;
            if (element.hasPointerCapture(event.pointerId)) element.releasePointerCapture(event.pointerId);
        });
        element.addEventListener('pointercancel', () => dragging = false);
    };
    addPointerDrag(svPicker, updateSv);
    addPointerDrag(huePicker, updateHue);

    svPicker?.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const delta = event.shiftKey ? .1 : .02;
        if (event.key === 'ArrowLeft') pickerState.s = clamp(pickerState.s - delta, 0, 1);
        if (event.key === 'ArrowRight') pickerState.s = clamp(pickerState.s + delta, 0, 1);
        if (event.key === 'ArrowUp') pickerState.v = clamp(pickerState.v + delta, 0, 1);
        if (event.key === 'ArrowDown') pickerState.v = clamp(pickerState.v - delta, 0, 1);
        commitPicker();
    });
    huePicker?.addEventListener('keydown', event => {
        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        const delta = event.shiftKey ? 15 : 2;
        pickerState.h = (pickerState.h + (['ArrowDown', 'ArrowRight'].includes(event.key) ? delta : -delta) + 360) % 360;
        commitPicker();
    });
    hexValue?.addEventListener('input', () => {
        hexValue.value = hexValue.value.replace(/[^0-9a-f]/gi, '').slice(0, 6).toUpperCase();
        if (hexValue.value.length === 6 && setPickerFromHex('#' + hexValue.value)) commitPicker();
    });
    hexValue?.addEventListener('blur', renderPicker);

    const pickerToggle = byId('imMonoPickerToggle');
    const closePicker = () => {
        if (!picker) return;
        if (typeof picker.close === 'function' && picker.open) picker.close();
        else picker.removeAttribute('open');
        pickerToggle?.setAttribute('aria-expanded', 'false');
    };
    pickerToggle?.addEventListener('click', () => {
        if (typeof picker.showModal === 'function') picker.showModal();
        else picker.setAttribute('open', '');
        pickerToggle.setAttribute('aria-expanded', 'true');
        setTimeout(() => svPicker?.focus(), 0);
    });
    byId('imMonoPickerClose')?.addEventListener('click', closePicker);
    picker?.addEventListener('close', () => pickerToggle?.setAttribute('aria-expanded', 'false'));
    picker?.addEventListener('click', event => {
        if (event.target === picker) closePicker();
    });

    setPickerFromHex(colorInputs[0]?.value || '#1D4ED8');
    syncCustomDot();
    syncColorPanels();
})();
