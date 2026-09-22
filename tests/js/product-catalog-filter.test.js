const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buildFormUrl, createLoader, controlledLink, syncHeadMetadata } = require('../../assets/js/product-catalog-filter');

test('GET forms keep canonical controls, omit empty values and reset pagination', () => {
    const savedLocation = global.location;
    global.location = new URL('https://example.test/products.html');
    try {
        const url = new URL(buildFormUrl('/products.html?page=9&utm_source=old', [
            ['keyword', 'bolt'], ['brand', '2,4'], ['pmin', ''], ['sort', 'newest'], ['page', '9'],
        ]));
        assert.equal(url.pathname, '/products.html');
        assert.deepEqual(Object.fromEntries(url.searchParams), {
            keyword: 'bolt', brand: '2,4', sort: 'newest',
        });
    } finally {
        if (savedLocation === undefined) delete global.location;
        else global.location = savedLocation;
    }
});

test('loader sends a same-origin GET contract and only returns the newest response', async () => {
    const pending = [];
    const calls = [];
    const loader = createLoader((url, options) => {
        calls.push([url, options]);
        return new Promise(resolve => pending.push(resolve));
    });
    const first = loader.load('/products.html?keyword=old');
    const second = loader.load('/products.html?keyword=new');

    assert.equal(calls[0][1].method, 'GET');
    assert.equal(calls[0][1].credentials, 'same-origin');
    assert.equal(calls[0][1].headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(calls[0][1].signal.aborted, true);
    assert.equal(calls[1][1].signal.aborted, false);

    pending[1]({ ok: true, url: 'https://example.test/products.html?keyword=new', text: async () => '<main>new</main>' });
    assert.deepEqual(await second, {
        html: '<main>new</main>', url: 'https://example.test/products.html?keyword=new',
    });
    pending[0]({ ok: true, url: 'https://example.test/products.html?keyword=old', text: async () => '<main>old</main>' });
    assert.equal(await first, null);
});

test('loader rejects non-success HTTP responses', async () => {
    const loader = createLoader(async () => ({ ok: false, text: async () => '' }));
    await assert.rejects(loader.load('/products.html'), /catalog-http/);
});

test('only links inside catalog controls are intercepted', () => {
    const root = { contains: () => true };
    const filter = {
        target: '', hasAttribute: () => false,
        closest: selector => selector.includes('data-catalog-facets') ? {} : null,
    };
    const pagination = {
        target: '', hasAttribute: () => false,
        closest: selector => selector.includes('data-catalog-pagination') ? {} : null,
    };
    const category = {
        target: '', hasAttribute: () => false,
        closest: selector => selector.includes('data-catalog-categories') ? {} : null,
    };
    const product = { target: '', hasAttribute: () => false, closest: () => null };
    assert.equal(controlledLink(filter, root), true);
    assert.equal(controlledLink(pagination, root), true);
    assert.equal(controlledLink(category, root), false);
    assert.equal(controlledLink(product, root), false);
});

test('AJAX navigation synchronizes canonical URL and document title from the fetched page', () => {
    const canonical = {
        href: 'https://example.test/products.html',
        getAttribute(name) { return name === 'href' ? this.href : null; },
        setAttribute(name, value) { if (name === 'href') this.href = value; },
    };
    const nextCanonical = {
        getAttribute: name => name === 'href'
            ? 'https://example.test/en/products/page/2.html?keyword=bolt%20nut'
            : null,
    };
    const doc = {
        title: 'Products - Example',
        querySelector: selector => selector === 'link[rel="canonical"]' ? canonical : null,
    };
    const parsed = {
        title: 'Filtered products - Example',
        querySelector: selector => selector === 'link[rel="canonical"]' ? nextCanonical : null,
    };

    syncHeadMetadata(doc, parsed);

    assert.equal(canonical.href, 'https://example.test/en/products/page/2.html?keyword=bolt%20nut');
    assert.equal(doc.title, 'Filtered products - Example');
});

test('AJAX metadata sync does not erase the current canonical URL or title when response metadata is absent', () => {
    const canonical = {
        href: 'https://example.test/products.html?keyword=bolt',
        getAttribute(name) { return name === 'href' ? this.href : null; },
        setAttribute(name, value) { if (name === 'href') this.href = value; },
    };
    const doc = {
        title: 'Products - Example',
        querySelector: selector => selector === 'link[rel="canonical"]' ? canonical : null,
    };
    const parsed = { title: '', querySelector: () => null };

    syncHeadMetadata(doc, parsed);

    assert.equal(canonical.href, 'https://example.test/products.html?keyword=bolt');
    assert.equal(doc.title, 'Products - Example');
});

test('boot quietly leaves native GET navigation in place when fetch is unavailable', () => {
    const savedFetch = global.fetch;
    delete global.fetch;
    try {
        const doc = { querySelector: () => ({}) };
        const { boot } = require('../../assets/js/product-catalog-filter');
        assert.equal(boot(doc), null);
    } finally {
        global.fetch = savedFetch;
    }
});
