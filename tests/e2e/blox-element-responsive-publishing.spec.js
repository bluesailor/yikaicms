const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const { addTemporaryHeading, frame, headingTextField, openPageEditor, performPagePreviewUpdate, expectClean, waitPreviewSettled } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-baseline-fixture.php'), action], { cwd: root });
const samples = {
  en: {
    title: 'Reliable digital services for international businesses with growing teams and increasingly complex customer requirements',
    body: 'Plan your next project with a team that understands your products, your customers and your long term goals. We explain each stage clearly and help your colleagues maintain accurate information across every page.',
    button: 'Discuss the requirements and delivery schedule for your next project',
  },
  ja: {
    title: '海外のお客様にもわかりやすく製品の価値を伝え、事業の成長と日々の情報更新を支えるウェブサイトをご提案します',
    body: '製品情報の整理から公開後の運用まで、お客様の事業に合わせて一つずつ丁寧に進めます。担当者が変わっても安心して情報を更新できるように、制作の流れと確認事項を明確にし、社内の皆様と共有します。',
    button: '次のプロジェクトの要件と公開までのスケジュールについて相談する',
  },
};
let cleanupThemes = () => {};
test.beforeAll(() => { cleanupThemes = installMarketThemes(root, ['business', 'minimal']); });
test.afterAll(() => { fixture('restore'); cleanupThemes(); });

async function command(page, action, button) {
  const pending = page.waitForResponse(response => {
    const body = new URLSearchParams(response.request().postData() || '');
    return new URL(response.url()).pathname === '/admin/blox_page_api.php' && body.get('action') === action;
  });
  if (action === 'publish') page.once('dialog', dialog => dialog.accept());
  await page.getByTestId(button).click();
  const response = await pending;
  expect(response.status()).toBe(200);
  expect((await response.json()).code).toBe(0);
  await expectClean(page);
}

for (const [language, copy] of Object.entries(samples)) {
  test(`long ${language} content preserves device inheritance through reopening and publishing @ci`, async ({ page, browser, baseURL }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'Editing once; anonymous 1440/768/390 checks run inside each case');
    test.setTimeout(180000);
    fixture('pretty');
    const marker = `E3 ${language} ${Date.now()}`;
    const title = `${marker}: ${copy.title}`;
    const evidence = [];
    const errors = [];
    await page.goto(fixtures.blox_page_url);
    const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
    expect(canonical.origin).toBe(new URL(baseURL).origin);
    expect(canonical.search).toBe('');
    const publicUrl = canonical.pathname;
    const surfaceIn = target => target.locator('.yk-container').filter({ has: target.getByRole('heading', { name: title, exact: true }) });
    const containerRow = () => page.locator('[data-testid="blox-tree-element"][data-element-type="container"]').last().locator('[data-element-drag-handle]');
    async function selectContainer() {
      await containerRow().click();
      await page.getByTestId('blox-style-tab').click();
    }
    // BloxValueSanitizer stores checkbox values as '0'/'1'; reopened documents return that form, fresh edits return booleans.
    const data = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).selEl.data,
      (key, item) => (key === 'new_tab' && typeof item === 'boolean' ? (item ? '1' : '0') : item))));
    async function addChild(type) {
      await selectContainer();
      await page.getByTestId('blox-library-open').click();
      await page.getByTestId(`blox-add-element-${type}`).press('Enter');
      await page.getByTestId('blox-content-tab').click();
    }
    async function reopen() {
      await command(page, 'save_draft', 'blox-save');
      await page.goto('/admin/page.php');
      await openPageEditor(page, fixtures.blox_page);
      await page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label').click();
      await selectContainer();
      await expectClean(page);
    }
    async function measure(target, width, restored, label) {
      const surface = surfaceIn(target);
      await expect(surface).toHaveCount(1);
      await expect(surface).toBeVisible();
      await expect(surface.getByRole('heading', { name: title, exact: true })).toHaveJSProperty('tagName', 'H2');
      await expect(surface.getByText(copy.body, { exact: true })).toBeVisible();
      const button = surface.getByRole('link', { name: copy.button, exact: true });
      await expect(button).toHaveAttribute('href', publicUrl + '#e3-responsive');
      await expect(button).not.toHaveAttribute('target', '_blank');
      const padding = width >= 1024 ? 24 : width >= 768 || restored ? 12 : 40;
      const gap = width >= 1024 ? 32 : width >= 768 ? 8 : 16;
      await expect(surface).toHaveCSS('padding-top', `${padding}px`);
      await expect(surface).toHaveCSS('gap', `${gap}px`);
      await expect(surface).toHaveCSS('flex-direction', 'column');
      const metrics = await surface.evaluate(node => {
        const bounds = node.getBoundingClientRect();
        const text = [...node.querySelectorAll('h2, p, a')].map(element => {
          const box = element.getBoundingClientRect();
          const range = document.createRange();
          range.selectNodeContents(element);
          const fragments = [...range.getClientRects()].map(rect => ({ left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom }));
          return { tag: element.tagName, left: box.left, right: box.right, top: box.top, bottom: box.bottom,
            clipsY: ['hidden', 'clip', 'scroll', 'auto'].includes(getComputedStyle(element).overflowY),
            overflow: element.scrollWidth - element.clientWidth, fragments };
        });
        return { viewport: innerWidth, documentOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          left: bounds.left, right: bounds.right, top: bounds.top, bottom: bounds.bottom, text };
      });
      await info.attach(`${label}-metrics`, { body: JSON.stringify(metrics, null, 2), contentType: 'application/json' });
      expect(metrics.viewport).toBe(width);
      expect(metrics.documentOverflow).toBeLessThanOrEqual(1);
      expect(metrics.text.map(item => item.tag)).toEqual(['H2', 'P', 'A']);
      for (const item of metrics.text) {
        expect(item.overflow, `${label} ${item.tag} horizontal clipping`).toBeLessThanOrEqual(1);
        expect(item.left).toBeGreaterThanOrEqual(metrics.left - 1);
        expect(item.right).toBeLessThanOrEqual(metrics.right + 1);
        for (const fragment of item.fragments) {
          expect(fragment.left).toBeGreaterThanOrEqual(item.left - 1);
          expect(fragment.right).toBeLessThanOrEqual(item.right + 1);
          // Glyph ascent can extend outside a line box with visible overflow.
          // Check real clipping boundaries and neighboring text, not font metrics.
          expect(fragment.top).toBeGreaterThanOrEqual((item.clipsY ? item.top : metrics.top) - 1);
          expect(fragment.bottom).toBeLessThanOrEqual((item.clipsY ? item.bottom : metrics.bottom) + 1);
        }
      }
      for (let index = 1; index < metrics.text.length; index++) {
        const current = metrics.text[index], previous = metrics.text[index - 1];
        expect(Math.min(current.top, ...current.fragments.map(part => part.top)))
          .toBeGreaterThanOrEqual(Math.max(previous.bottom, ...previous.fragments.map(part => part.bottom)) - 1);
      }
      if (width === 390) expect(metrics.text[0].fragments.length).toBeGreaterThan(1);
      evidence.push({ label, restored, padding, gap, ...metrics });
      return surface;
    }
    async function visitMatrix(restored) {
      for (const theme of ['default', 'business', 'minimal']) {
        fixture(theme === 'default' ? 'pretty' : theme);
        for (const width of [1440, 768, 390]) {
          const context = await browser.newContext({ baseURL, viewport: { width, height: 960 }, reducedMotion: 'reduce', storageState: { cookies: [], origins: [] } });
          try {
            const visitor = await context.newPage();
            visitor.on('pageerror', error => errors.push(error.message));
            visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
            visitor.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
            const response = await visitor.goto(publicUrl);
            expect(response.status()).toBe(200);
            await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
            if (theme === 'minimal') await expect(visitor.locator('body')).toHaveClass(/minimal-theme/);
            else if (theme === 'default') await expect(visitor.locator('body')).toHaveClass(/yk-site-body/);
            else {
              await expect(visitor.locator('body')).toHaveClass(/business-theme/);
              await expect(visitor.locator('#siteHeader')).toBeAttached();
            }
            const label = `${theme}-${width}-${restored ? 'inherited' : 'override'}`;
            const surface = await measure(visitor, width, restored, label);
            await surface.screenshot({ path: info.outputPath(`${label}.png`) });
          } finally { await context.close(); }
        }
      }
      fixture('pretty');
    }

    await openPageEditor(page, fixtures.blox_page);
    await addTemporaryHeading(page);
    await performPagePreviewUpdate(page, () => headingTextField(page).fill(marker));
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId('blox-add-element-container').press('Enter');
    await addChild('heading');
    await performPagePreviewUpdate(page, () => headingTextField(page).fill(title));
    await addChild('text');
    await page.getByTestId('blox-richtext-edit').click();
    const dialog = page.locator('[aria-labelledby="blox-rte-dialog-title"]');
    await dialog.frameLocator('iframe').locator('body').fill(copy.body);
    await performPagePreviewUpdate(page, () => dialog.getByRole('button').last().click());
    await addChild('button');
    await performPagePreviewUpdate(page, async () => {
      await page.locator('[data-control-key="text"] input[type="text"]').fill(copy.button);
      await page.locator('[data-control-key="url"] input[type="text"]').fill(publicUrl + '#e3-responsive');
    });
    await selectContainer();
    for (const [device, padding, gap] of [['desktop', 'md', 'lg'], ['tablet', 'sm', 'sm'], ['mobile', 'lg', 'md']]) {
      await page.getByTestId(`blox-container-responsive-device-${device}`).click();
      await performPagePreviewUpdate(page, async () => {
        await page.getByTestId(`blox-container-padding-${padding}`).click();
        await page.getByTestId(`blox-container-gap-${gap}`).click();
      });
    }
    const before = await data();
    expect(before.padding).toEqual({ d: 'md', t: 'sm', m: 'lg' });
    expect(before.gap).toEqual({ d: 'lg', t: 'sm', m: 'md' });
    expect(before.children.find(child => child.type === 'button').data.new_tab).toBe('0');
    await reopen();
    expect(await data()).toEqual(before);
    await command(page, 'publish', 'blox-publish-page');
    await visitMatrix(false);

    await page.getByTestId('blox-container-responsive-device-mobile').click();
    await expect(page.getByTestId('blox-container-padding-inherit')).toBeVisible();
    await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-padding-inherit').click());
    const inherited = { ...before, padding: { d: 'md', t: 'sm' } };
    expect(await data()).toEqual(inherited);
    await expect(page.getByTestId('blox-container-padding-inherit')).toBeHidden();
    await expect(page.getByTestId('blox-container-gap-inherit')).toBeVisible();
    await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
    await selectContainer();
    expect(await data()).toEqual(before);
    await expectClean(page);
    await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
    await selectContainer();
    expect(await data()).toEqual(inherited);
    await reopen();
    expect(await data()).toEqual(inherited);
    for (const [device, width] of [['desktop', 1280], ['tablet', 768], ['mobile', 390]]) {
      await page.getByTestId(`blox-container-responsive-device-${device}`).click();
      await waitPreviewSettled(page);
      await measure(await frame(page), width, true, `canvas-${device}`);
    }
    await command(page, 'publish', 'blox-publish-page');
    await visitMatrix(true);
    expect(errors).toEqual([]);
    await info.attach('responsive-publishing-evidence', { body: JSON.stringify({ language, publicUrl, evidence }, null, 2), contentType: 'application/json' });
  });
}
