const { test } = require('node:test');
const assert = require('node:assert/strict');
const { fragmentUrl, cleanUrl, buildFilterUrl } = require('../../assets/js/yikay-query');

test('fragment requests reuse the page URL and never leak the fragment flag back', () => {
    const base = 'https://example.test/news/?ykq_a1=2';
    const fragment = new URL(fragmentUrl('/news/?ykq_a1=3#list', 'host-1', base));
    assert.equal(fragment.pathname, '/news/');
    assert.equal(fragment.searchParams.get('_ykq'), 'host-1');
    assert.equal(fragment.searchParams.get('ykq_a1'), '3');
    assert.equal(cleanUrl(fragment.toString(), base), '/news/?ykq_a1=3#list');
});

test('filter URLs replace only their own namespace, reset the page and merge multi-values', () => {
    const url = new URL(buildFilterUrl(
        'https://example.test/list?utm=x&yfabc123_s=old&yfabc123_cat=9&yfzzz999_s=keep&ykq_p=4&_ykq=h',
        'yfabc123',
        'ykq_p',
        [
            ['yfabc123_s', ' shoes '],
            ['yfabc123_cat[]', '2'],
            ['yfabc123_cat[]', '5'],
            ['yfabc123_cat[]', '2'],
            ['yfabc123_sort', ''],
            ['other', 'ignored'],
        ]
    ));
    assert.deepEqual(Object.fromEntries(url.searchParams), {
        utm: 'x',
        yfzzz999_s: 'keep',
        yfabc123_s: 'shoes',
        yfabc123_cat: '2,5',
    });
});

test('clearing every field removes the namespace entirely', () => {
    const url = new URL(buildFilterUrl('https://example.test/list?yfabc123_s=a&page=2', 'yfabc123', 'ykq_p', [['yfabc123_s', '']]));
    assert.equal(url.search, '?page=2');
});
