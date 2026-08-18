(() => {
    'use strict';

    const canvas = document.getElementById('imDrawStage');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const root = document.getElementById('im-pane-draw');
    const status = document.getElementById('imDrawStatus');
    const state = {
        tool: 'select',
        fill: '#2563eb',
        stroke: '#17202a',
        strokeWidth: 8,
        text: 'YK',
        objects: [],
        selected: -1,
        draft: null,
        start: null,
        drag: null,
        history: [[]],
        historyIndex: 0,
    };

    const $ = id => document.getElementById(id);
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const clone = value => JSON.parse(JSON.stringify(value));
    const escapeXml = value => String(value).replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;'
    }[character]));

    function pointFromEvent(event) {
        const rect = canvas.getBoundingClientRect();
        return {
            x: clamp((event.clientX - rect.left) * canvas.width / rect.width, 0, canvas.width),
            y: clamp((event.clientY - rect.top) * canvas.height / rect.height, 0, canvas.height),
        };
    }

    function setStatus(message) {
        if (status) status.textContent = message;
    }

    function snapshot() {
        return clone(state.objects);
    }

    function commit() {
        state.history = state.history.slice(0, state.historyIndex + 1);
        state.history.push(snapshot());
        state.historyIndex = state.history.length - 1;
        updateActions();
    }

    function restore(index) {
        if (!state.history[index]) return;
        state.objects = clone(state.history[index]);
        state.selected = -1;
        state.historyIndex = index;
        render();
        updateActions();
    }

    function updateActions() {
        $('imDrawUndo').disabled = state.historyIndex <= 0;
        $('imDrawRedo').disabled = state.historyIndex >= state.history.length - 1;
    }

    function drawGrid() {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 512, 512);
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 1;
        for (let value = 16; value < 512; value += 32) {
            ctx.beginPath();
            ctx.moveTo(value + .5, 0);
            ctx.lineTo(value + .5, 512);
            ctx.moveTo(0, value + .5);
            ctx.lineTo(512, value + .5);
            ctx.stroke();
        }
        ctx.strokeStyle = '#94a3b8';
        ctx.strokeRect(32.5, 32.5, 447, 447);
        ctx.beginPath();
        ctx.moveTo(256.5, 0);
        ctx.lineTo(256.5, 512);
        ctx.moveTo(0, 256.5);
        ctx.lineTo(512, 256.5);
        ctx.stroke();
    }

    function starPoints(cx, cy, outer, inner, count = 5) {
        const points = [];
        for (let index = 0; index < count * 2; index++) {
            const radius = index % 2 === 0 ? outer : inner;
            const angle = -Math.PI / 2 + index * Math.PI / count;
            points.push([cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius]);
        }
        return points;
    }

    function drawSmoothPath(context, points) {
        if (points.length < 2) return;
        context.beginPath();
        context.moveTo(points[0][0], points[0][1]);
        if (points.length === 2) {
            context.lineTo(points[1][0], points[1][1]);
            context.stroke();
            return;
        }
        for (let index = 1; index < points.length - 1; index++) {
            const current = points[index];
            const next = points[index + 1];
            const midpoint = [(current[0] + next[0]) / 2, (current[1] + next[1]) / 2];
            context.quadraticCurveTo(current[0], current[1], midpoint[0], midpoint[1]);
        }
        const last = points[points.length - 1];
        const previous = points[points.length - 2];
        context.quadraticCurveTo(previous[0], previous[1], last[0], last[1]);
        context.stroke();
    }

    function smoothPathData(points) {
        if (points.length < 2) return '';
        if (points.length === 2) return `M ${points[0][0]} ${points[0][1]} L ${points[1][0]} ${points[1][1]}`;
        let data = `M ${points[0][0]} ${points[0][1]}`;
        for (let index = 1; index < points.length - 1; index++) {
            const current = points[index];
            const next = points[index + 1];
            const midpoint = [(current[0] + next[0]) / 2, (current[1] + next[1]) / 2];
            data += ` Q ${current[0]} ${current[1]} ${midpoint[0]} ${midpoint[1]}`;
        }
        const previous = points[points.length - 2];
        const last = points[points.length - 1];
        return data + ` Q ${previous[0]} ${previous[1]} ${last[0]} ${last[1]}`;
    }

    function drawObject(object) {
        ctx.save();
        ctx.lineWidth = object.strokeWidth || 1;
        ctx.strokeStyle = object.stroke || 'transparent';
        ctx.fillStyle = object.fill || 'transparent';
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        if (object.type === 'rect') {
            ctx.fillRect(object.x, object.y, object.w, object.h);
            if (object.strokeWidth) ctx.strokeRect(object.x, object.y, object.w, object.h);
        } else if (object.type === 'ellipse') {
            ctx.beginPath();
            ctx.ellipse(object.x + object.w / 2, object.y + object.h / 2, Math.abs(object.w / 2), Math.abs(object.h / 2), 0, 0, Math.PI * 2);
            ctx.fill();
            if (object.strokeWidth) ctx.stroke();
        } else if (object.type === 'triangle' || object.type === 'star') {
            const points = object.type === 'star'
                ? starPoints(object.x + object.w / 2, object.y + object.h / 2, Math.min(Math.abs(object.w), Math.abs(object.h)) / 2, Math.min(Math.abs(object.w), Math.abs(object.h)) / 4)
                : [[object.x + object.w / 2, object.y], [object.x + object.w, object.y + object.h], [object.x, object.y + object.h]];
            ctx.beginPath();
            points.forEach(([x, y], index) => index ? ctx.lineTo(x, y) : ctx.moveTo(x, y));
            ctx.closePath();
            ctx.fill();
            if (object.strokeWidth) ctx.stroke();
        } else if (object.type === 'line') {
            ctx.beginPath();
            ctx.moveTo(object.x, object.y);
            ctx.lineTo(object.x + object.w, object.y + object.h);
            ctx.stroke();
        } else if (object.type === 'brush') {
            if (object.points.length < 2) { ctx.restore(); return; }
            drawSmoothPath(ctx, object.points);
        } else if (object.type === 'text') {
            ctx.font = '700 ' + object.size + 'px Arial, "Microsoft YaHei", sans-serif';
            ctx.textBaseline = 'top';
            ctx.fillText(object.text, object.x, object.y);
        }
        ctx.restore();
    }

    function bounds(object) {
        if (object.type === 'text') {
            ctx.save();
            ctx.font = '700 ' + object.size + 'px Arial, sans-serif';
            const width = ctx.measureText(object.text).width;
            ctx.restore();
            return {x: object.x, y: object.y, w: width, h: object.size};
        }
        if (object.type === 'brush') {
            const xs = object.points.map(point => point[0]);
            const ys = object.points.map(point => point[1]);
            return {x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys)};
        }
        return {x: Math.min(object.x, object.x + object.w), y: Math.min(object.y, object.y + object.h), w: Math.abs(object.w), h: Math.abs(object.h)};
    }

    function hit(object, point) {
        const box = bounds(object);
        return point.x >= box.x - 12 && point.x <= box.x + box.w + 12 && point.y >= box.y - 12 && point.y <= box.y + box.h + 12;
    }

    function drawSelection(object) {
        const box = bounds(object);
        ctx.save();
        ctx.strokeStyle = '#2563eb';
        ctx.lineWidth = 2;
        ctx.setLineDash([6, 4]);
        ctx.strokeRect(box.x - 6, box.y - 6, Math.max(12, box.w + 12), Math.max(12, box.h + 12));
        ctx.restore();
    }

    function render() {
        drawGrid();
        state.objects.forEach(drawObject);
        if (state.draft) drawObject(state.draft);
        if (state.selected >= 0 && state.objects[state.selected]) drawSelection(state.objects[state.selected]);
    }

    function newShape(tool, start, end) {
        const x = Math.min(start.x, end.x);
        const y = Math.min(start.y, end.y);
        const w = end.x - start.x;
        const h = end.y - start.y;
        const common = {fill: state.fill, stroke: state.stroke, strokeWidth: state.strokeWidth};
        if (tool === 'line') return {type: 'line', x: start.x, y: start.y, w, h, ...common, fill: 'transparent'};
        return {type: tool, x, y, w, h, ...common};
    }

    function normalized(object) {
        if (object.type === 'line') return object;
        if (object.type === 'brush' || object.type === 'text') return object;
        if (object.w < 0) { object.x += object.w; object.w *= -1; }
        if (object.h < 0) { object.y += object.h; object.h *= -1; }
        return object;
    }

    function svgForObjects(objects) {
        const body = objects.map(object => {
            const fill = escapeXml(object.fill || 'none');
            const stroke = escapeXml(object.stroke || 'none');
            const width = Math.max(0, Number(object.strokeWidth) || 0);
            if (object.type === 'rect') return `<rect x="${object.x}" y="${object.y}" width="${object.w}" height="${object.h}" fill="${fill}" stroke="${stroke}" stroke-width="${width}"/>`;
            if (object.type === 'ellipse') return `<ellipse cx="${object.x + object.w / 2}" cy="${object.y + object.h / 2}" rx="${Math.abs(object.w / 2)}" ry="${Math.abs(object.h / 2)}" fill="${fill}" stroke="${stroke}" stroke-width="${width}"/>`;
            if (object.type === 'line') return `<line x1="${object.x}" y1="${object.y}" x2="${object.x + object.w}" y2="${object.y + object.h}" stroke="${stroke}" stroke-width="${width}" stroke-linecap="round"/>`;
            if (object.type === 'text') return `<text x="${object.x}" y="${object.y + object.size}" fill="${fill}" font-family="Arial, Microsoft YaHei, sans-serif" font-size="${object.size}" font-weight="700">${escapeXml(object.text)}</text>`;
            if (object.type === 'brush') return `<path d="${smoothPathData(object.points)}" fill="none" stroke="${stroke}" stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round"/>`;
            const points = object.type === 'star'
                ? starPoints(object.x + object.w / 2, object.y + object.h / 2, Math.min(object.w, object.h) / 2, Math.min(object.w, object.h) / 4).map(point => point.join(','))
                : object.type === 'triangle'
                    ? [[object.x + object.w / 2, object.y], [object.x + object.w, object.y + object.h], [object.x, object.y + object.h]].map(point => point.join(','))
                    : [];
            return `<polygon points="${points.join(' ')}" fill="${fill}" stroke="${stroke}" stroke-width="${width}"/>`;
        }).join('');
        return `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">${body}</svg>`;
    }

    document.querySelectorAll('[data-im-draw-tool]').forEach(button => button.addEventListener('click', () => {
        state.tool = button.dataset.imDrawTool;
        document.querySelectorAll('[data-im-draw-tool]').forEach(item => item.classList.toggle('is-active', item === button));
        setStatus(state.tool === 'select' ? '选择对象后可拖动。' : state.tool === 'text' ? '点击画布放置文字。' : '在画布上拖动绘制。');
    }));

    $('imDrawFill').addEventListener('input', event => { state.fill = event.target.value; });
    $('imDrawStroke').addEventListener('input', event => { state.stroke = event.target.value; });
    $('imDrawStrokeWidth').addEventListener('input', event => {
        state.strokeWidth = Number(event.target.value);
        $('imDrawStrokeValue').textContent = state.strokeWidth + 'px';
    });

    canvas.addEventListener('pointerdown', event => {
        const point = pointFromEvent(event);
        canvas.setPointerCapture(event.pointerId);
        if (state.tool === 'select') {
            state.selected = -1;
            for (let index = state.objects.length - 1; index >= 0; index--) {
                if (hit(state.objects[index], point)) { state.selected = index; break; }
            }
            if (state.selected >= 0) state.drag = {point, object: clone(state.objects[state.selected])};
            render();
            return;
        }
        if (state.tool === 'text') {
            const text = String($('imDrawText').value || 'YK').trim() || 'YK';
            state.objects.push({type: 'text', text, x: point.x, y: point.y, size: 72, fill: state.fill, stroke: 'transparent', strokeWidth: 0});
            state.selected = state.objects.length - 1;
            commit();
            render();
            return;
        }
        if (state.tool === 'brush') {
            state.draft = {type: 'brush', points: [[point.x, point.y]], fill: 'transparent', stroke: state.stroke, strokeWidth: state.strokeWidth};
        } else {
            state.start = point;
            state.draft = newShape(state.tool, point, point);
        }
        render();
    });

    canvas.addEventListener('pointermove', event => {
        const point = pointFromEvent(event);
        if (state.drag && state.selected >= 0) {
            const object = state.objects[state.selected];
            const original = state.drag.object;
            const dx = point.x - state.drag.point.x;
            const dy = point.y - state.drag.point.y;
            object.x = original.x + dx;
            object.y = original.y + dy;
            render();
            return;
        }
        if (!state.draft) return;
        if (state.draft.type === 'brush') state.draft.points.push([point.x, point.y]);
        else state.draft = newShape(state.tool, state.start, point);
        render();
    });

    canvas.addEventListener('pointerup', () => {
        if (state.drag) { state.drag = null; commit(); render(); return; }
        if (!state.draft) return;
        const object = normalized(state.draft);
        if (object.type === 'brush' ? object.points.length > 1 : Math.abs(object.w || 0) > 3 || Math.abs(object.h || 0) > 3) {
            state.objects.push(object);
            state.selected = state.objects.length - 1;
            commit();
        }
        state.draft = null;
        state.start = null;
        render();
    });

    $('imDrawUndo').onclick = () => { if (state.historyIndex > 0) restore(state.historyIndex - 1); };
    $('imDrawRedo').onclick = () => { if (state.historyIndex < state.history.length - 1) restore(state.historyIndex + 1); };
    $('imDrawClear').onclick = () => { state.objects = []; state.selected = -1; commit(); render(); setStatus('画布已清空。'); };
    $('imDrawUseLogo').onclick = () => {
        if (!state.objects.length) return alert('请先绘制至少一个图形');
        if (typeof window.logoMakerUseSvg === 'function') window.logoMakerUseSvg(svgForObjects(state.objects));
    };
    $('imDrawUseFavicon').onclick = () => {
        if (!state.objects.length) return alert('请先绘制至少一个图形');
        if (typeof window.logoMakerApplyFaviconFromSvg === 'function') {
            window.logoMakerApplyFaviconFromSvg(svgForObjects(state.objects));
        }
    };

    document.addEventListener('keydown', event => {
        if (!root || root.classList.contains('hidden')) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            $('imDrawUndo').click();
        } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
            event.preventDefault();
            $('imDrawRedo').click();
        } else if (event.key === 'Delete' && state.selected >= 0 && !/INPUT|TEXTAREA/.test(document.activeElement?.tagName || '')) {
            state.objects.splice(state.selected, 1);
            state.selected = -1;
            commit();
            render();
        }
    });

    render();
    updateActions();
})();
