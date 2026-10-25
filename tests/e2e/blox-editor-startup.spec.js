const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, waitPreviewSettled, expectClean } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop startup baseline');
});

const rtePath = url => new URL(url).pathname.endsWith('/assets/hugerte/hugerte.min.js');
const openDialog = async (page, html = '<p>Retained content</p>') => page.evaluate(content => {
  window.Alpine.$data(document.body).openRte(() => content, value => { window.__rteApplied = value; }, false);
}, html);
const cancelDialog = page => page.locator('[x-ref="rteDialog"] [aria-label]').first().click();
const readyEditor = page => page.waitForFunction(() => window.hugerte && window.hugerte.get('bloxRte') && window.hugerte.get('bloxRte').initialized);

test('startup uses cached core methods and does not request HugeRTE @ci', async ({ page }) => {
  const requested = [];
  page.on('request', request => requested.push(request.url()));
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page);
  expect(requested.filter(rtePath)).toEqual([]);
  expect(requested.filter(url => new URL(url).pathname.endsWith('/assets/js/yikay-editor-runtime.js'))).toHaveLength(1);
  const metrics = await page.evaluate(() => {
    const scripts = [...document.head.querySelectorAll('script[src]')];
    const core = scripts.findIndex(node => node.src.includes('/yikay-editor-runtime.js'));
    const alpine = scripts.findIndex(node => node.src.includes('/alpinejs/alpine.min.js'));
    const inline = [...document.querySelectorAll('script:not([src])')].map(node => node.textContent).join('\n');
    const syncHeadScripts = [...document.head.querySelectorAll('script[src]')].filter(s => !s.defer && !s.async).map(s => new URL(s.src).pathname);
    const allBeforeAlpine = scripts.slice(alpine + 1).length === 0;
    const app = window.Alpine.$data(document.body);
    return { core, alpine, syncHeadScripts, allBeforeAlpine, afterAlpine: scripts.slice(alpine + 1).map(s => new URL(s.src).pathname), deferred: scripts[core].defer, inlineBytes: new TextEncoder().encode(inline).length,
      coreStillInline: inline.includes('initTreeSortable() {'),
      equal: app.documentData() === JSON.stringify({ schema: 1, settings: app.docSettings || {}, sections: app.sections || [] }) };
  });
  console.log('[startup] ' + JSON.stringify(metrics));
  expect(metrics.core).toBeLessThan(metrics.alpine);
  expect(metrics.deferred).toBe(true);
  expect(metrics.syncHeadScripts).toEqual(['/assets/js/admin-error-report.js']);
  expect(metrics.allBeforeAlpine).toBe(true);
  await expect(page.locator('script[src*="admin-image-editor.js"]')).toHaveAttribute('defer', '');
  expect(metrics.inlineBytes).toBeLessThan(600000);
  expect(metrics.coreStillInline).toBe(false);
  expect(metrics.equal).toBe(true);
  await expectClean(page);
});

test('rich-text dialogs load once on first use and retain content on reopen @ci', async ({ page }) => {
  const requests = [];
  page.on('request', request => { if (rtePath(request.url())) requests.push(request.url()); });
  await openPageEditor(page, fixtures.blox_page);
  expect(requests).toHaveLength(0);
  await openDialog(page);
  await readyEditor(page);
  expect(await page.evaluate(() => window.hugerte.get('bloxRte').getContent())).toBe('<p>Retained content</p>');
  expect(await page.evaluate(() => window.tinymce === window.hugerte)).toBe(true);
  await cancelDialog(page);
  await openDialog(page, '<p>Second content</p>');
  await readyEditor(page);
  expect(await page.evaluate(() => window.hugerte.get('bloxRte').getContent())).toBe('<p>Second content</p>');
  expect(requests).toHaveLength(1);
  await cancelDialog(page);
  await expectClean(page);
});

test('closing a dialog during library loading cannot initialize an obsolete editor @ci', async ({ page }) => {
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  let entered;
  const pending = new Promise(resolve => { entered = resolve; });
  await page.route('**/assets/hugerte/hugerte.min.js*', async route => {
    entered();
    await gate;
    await route.fulfill({ response: await route.fetch() });
  });
  await openPageEditor(page, fixtures.blox_page);
  await openDialog(page, '<p>Cancelled</p>');
  await pending;
  await cancelDialog(page);
  release();
  await page.waitForFunction(() => window.hugerte && window.hugerte.init);
  expect(await page.evaluate(() => !!window.hugerte.get('bloxRte'))).toBe(false);
  await openDialog(page, '<p>Current</p>');
  await readyEditor(page);
  expect(await page.evaluate(() => window.hugerte.get('bloxRte').getContent())).toBe('<p>Current</p>');
  await cancelDialog(page);
  await expectClean(page);
});

test('text entered while the library downloads survives rich-text initialization @ci', async ({ page }) => {
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  let entered;
  const pending = new Promise(resolve => { entered = resolve; });
  await page.route('**/assets/hugerte/hugerte.min.js*', async route => {
    entered();
    await gate;
    await route.fulfill({ response: await route.fetch() });
  });
  await openPageEditor(page, fixtures.blox_page);
  await openDialog(page);
  await pending;
  await page.locator('#bloxRte').fill('<p>Typed during download</p>');
  release();
  await readyEditor(page);
  expect(await page.evaluate(() => window.hugerte.get('bloxRte').getContent())).toBe('<p>Typed during download</p>');
  await cancelDialog(page);
  await expectClean(page);
});

test('a failed rich-text load preserves the textarea and can retry without publishing @ci', async ({ page, expectedHttpErrors, baseURL }) => {
  await openPageEditor(page, fixtures.blox_page);
  const src = await page.locator('script[data-hugerte-src]').getAttribute('data-hugerte-src');
  expectedHttpErrors.push({ status: 404, url: new URL(src, baseURL).toString(), method: 'GET', action: null });
  const fail = route => route.fulfill({ status: 404, body: 'Not found' });
  await page.route('**/assets/hugerte/hugerte.min.js*', fail);
  await openDialog(page);
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await expect(page.locator('#bloxRte')).toHaveValue('<p>Retained content</p>');
  await page.locator('#bloxRte').fill('<p>Source fallback</p>');
  await page.locator('[x-ref="rteDialog"] button').last().click();
  expect(await page.evaluate(() => window.__rteApplied)).toBe('<p>Source fallback</p>');
  await page.unroute('**/assets/hugerte/hugerte.min.js*', fail);
  await openDialog(page, '<p>Retry</p>');
  await readyEditor(page);
  expect(await page.evaluate(() => window.hugerte.get('bloxRte').getContent())).toBe('<p>Retry</p>');
  await cancelDialog(page);
  await expectClean(page);
});

for (const dependency of ['yikay-responsive.js', 'yikay-pro-editor.js']) {
  test(`Alpine waits for deferred dependency ${dependency} while HTML parses @ci`, async ({ page }) => {
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    let entered;
    const pending = new Promise(resolve => { entered = resolve; });
    await page.route(`**/${dependency}*`, async route => {
      entered();
      await gate;
      await route.fulfill({ response: await route.fetch() });
    });
    try {
      await page.goto(`/admin/blox_editor.php?id=${fixtures.blox_page}`, { waitUntil: 'commit' });
      await pending;
      // The document must finish parsing even though one dependency has not downloaded.
      await page.waitForFunction(() => document.readyState === 'interactive');
      expect(await page.locator('[data-testid="blox-canvas"]').count()).toBe(1);
      expect(await page.evaluate(() => typeof window.Alpine)).toBe('undefined');
    } finally { release(); }
    await page.waitForLoadState('domcontentloaded');
    await waitPreviewSettled(page);
    expect(await page.evaluate(() => typeof window.Alpine.$data(document.body).documentData)).toBe('function');
    expect(await page.evaluate(() => typeof window.BloxProEditor.methods)).toBe('object');
    await expectClean(page);
  });
}
