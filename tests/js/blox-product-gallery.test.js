const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = path.join(__dirname, '..', '..', 'assets', 'js', 'blox-product-gallery.js');

/** 最小锚点替身：只有绑定代码真正会读的属性与方法。 */
function makeLink(src, alt) {
    const attrs = { 'data-yk-gallery-alt': alt || '' };
    if (src !== undefined) {
        attrs.href = src;
        attrs['data-yk-gallery-full'] = src;
    }
    const link = {
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
        },
    };
    link.closest = (selector) => (selector === '[data-yk-gallery-item]' ? link : null);
    return link;
}

function makeContainer(links) {
    return {
        dataset: {},
        listeners: {},
        querySelectorAll(selector) {
            return selector === '[data-yk-gallery-item]' ? links : [];
        },
        contains(node) {
            return links.indexOf(node) !== -1;
        },
        addEventListener(type, handler) {
            (this.listeners[type] = this.listeners[type] || []).push(handler);
        },
        click(target) {
            let prevented = false;
            const event = { target: target, preventDefault: () => { prevented = true; } };
            (this.listeners.click || []).forEach((handler) => handler(event));
            return prevented;
        },
    };
}

/**
 * 载入绑定脚本。photoSwipe=false 表示脚本/资源没到——那是降级路径，必须仍然可用。
 * 返回的 scope 代表"页面"（document.querySelectorAll('[data-yk-gallery]') 的替身）。
 */
function load(containers, usePhotoSwipe) {
    const opened = [];
    const probed = [];
    const scope = {
        querySelectorAll: (selector) => (selector === '[data-yk-gallery]' ? containers : []),
    };
    const context = {
        Image: function Image() {
            const image = { naturalWidth: 1200, naturalHeight: 800, onload: null };
            Object.defineProperty(image, 'src', {
                set(value) {
                    probed.push(value);
                    if (typeof image.onload === 'function') image.onload();
                },
                get() { return ''; },
            });
            return image;
        },
        document: {
            readyState: 'complete',
            querySelectorAll: scope.querySelectorAll,
            addEventListener() {},
        },
    };
    context.window = context;

    if (usePhotoSwipe) {
        context.PhotoSwipe = function PhotoSwipe() {};
        context.PhotoSwipeLightbox = function PhotoSwipeLightbox(options) {
            const instance = { options: options, inited: false, openedAt: null };
            opened.push(instance);
            this.init = function () { instance.inited = true; };
            this.loadAndOpen = function (index) { instance.openedAt = index; };
            this.on = function () {};
        };
    }

    vm.createContext(context);
    vm.runInContext(fs.readFileSync(SOURCE, 'utf8'), context);
    return { gallery: context.YikaiBloxProductGallery, scope, opened, probed };
}

test('binds each gallery container exactly once', () => {
    const links = [makeLink('/a.jpg'), makeLink('/b.jpg')];
    const container = makeContainer(links);
    const { gallery, scope } = load([container], false);

    assert.equal(container.dataset.ykGalleryBound, '1', '脚本载入即自动绑定（画布/前台都要）');
    gallery.init(scope);
    assert.equal(container.listeners.click.length, 1, '重复 init 不得重复绑定');
});

test('opens PhotoSwipe with every image and the clicked index', () => {
    const links = [makeLink('/a.jpg', 'A'), makeLink('/b.jpg', 'B')];
    const container = makeContainer(links);
    const { opened, probed } = load([container], true);

    assert.deepEqual(probed, ['/a.jpg', '/b.jpg'], '载入时预探测真实尺寸');

    const prevented = container.click(links[1]);

    assert.equal(prevented, true, '灯箱接管后要拦掉锚点默认跳转');
    assert.equal(opened.length, 1, '只建一个灯箱实例');
    assert.equal(opened[0].inited, true);
    assert.equal(opened[0].openedAt, 1, '从被点的那张开始');
    assert.equal(opened[0].options.showHideAnimationType, 'zoom', '与原生同一套灯箱行为');
    // 跨 vm 域取回宿主数组再比，避免原型不同导致 deepStrictEqual 误判
    const dataSource = [...opened[0].options.dataSource];
    assert.deepEqual(
        dataSource.map((item) => item.src),
        ['/a.jpg', '/b.jpg'],
        'dataSource 覆盖整组图（可左右滑动）'
    );
    assert.deepEqual(
        dataSource.map((item) => [item.width, item.height]),
        [[1200, 800], [1200, 800]],
        '使用探测到的宽高，缩放动画才对得上'
    );
    assert.equal(dataSource[1].alt, 'B');
    assert.equal(typeof opened[0].options.pswpModule, 'function', '必须把 PhotoSwipe 模块交给灯箱');
});

test('degrades to a plain link when the lightbox engine never loaded', () => {
    const links = [makeLink('/a.jpg')];
    const container = makeContainer(links);
    const { opened } = load([container], false);

    const prevented = container.click(links[0]);

    assert.equal(prevented, false, '没有 PhotoSwipe 时不能吞掉点击，图片仍要看得到');
    assert.equal(opened.length, 0);
});

test('ignores clicks that are not on a gallery item', () => {
    const links = [makeLink('/a.jpg')];
    const container = makeContainer(links);
    const { opened } = load([container], true);

    const prevented = container.click({ closest: () => null });

    assert.equal(prevented, false);
    assert.equal(opened.length, 0);
});

test('falls back to href and drops items without any source', () => {
    const withHrefOnly = {
        _attrs: { href: '/only-href.jpg' },
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null;
        },
    };
    withHrefOnly.closest = () => withHrefOnly;

    const links = [makeLink(), withHrefOnly];
    const container = makeContainer(links);
    const { opened } = load([container], true);

    container.click(withHrefOnly);

    assert.deepEqual(
        [...opened[0].options.dataSource].map((item) => item.src),
        ['/only-href.jpg'],
        'data-yk-gallery-full 缺失时用 href；两者都没有的图片不进灯箱'
    );
    assert.equal(opened[0].openedAt, 0, '索引按过滤后的有效图算，不能因缺图而错位');
});
