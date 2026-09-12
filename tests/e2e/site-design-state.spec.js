const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
// Trace instrumentation tries to execute scripts inside script-disabled frames.
// Keep browser/PHP error assertions and screenshots without weakening the sandbox.
test.use({ trace: 'off' });
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'site-design-state-fixture.php'), action], { cwd: root, encoding: 'utf8' });

test('overview resolves published context instead of the latest template and keeps drafts private @ci', async ({ page, browser }, info) => {
  const state = JSON.parse(fixture('seed'));
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const front = await visitor.newPage();
  const url = `/admin/site_design.php?context=page%3A${state.page}`;
  try {
    await page.goto(url);
    const snapshotRequests = [];
    page.on('request', request => {
      if (request.resourceType() === 'fetch' && !new URL(request.url()).pathname.startsWith('/admin/')) snapshotRequests.push(request);
    });
    for (const area of ['header', 'footer']) {
      const row = page.getByTestId(`site-design-area-${area}`);
      await expect(row.getByTestId('site-design-area-source')).toContainText(`TB ${area} active`);
      expect((await row.locator('.site-design-area-copy').boundingBox()).width).toBeGreaterThanOrEqual(200);
      await expect(row).not.toContainText(`TB ${area} other`);
      await expect(row).not.toContainText(`TB ${area} draft`);
      const edit = new URL(await row.getByTestId('site-design-area-edit').getAttribute('href'), page.url());
      expect(edit.searchParams.get('template')).toBe(String(state[`${area}_active`]));
      expect(edit.searchParams.get('preview_context')).toBe(`page:${state.page}`);
      const panel = row.locator('[data-site-area-preview]');
      await expect(panel.locator('iframe')).toHaveCount(0);
      await panel.locator('summary').click();
      await expect(panel).toHaveAttribute('data-preview-ready', 'true');
      await expect(panel.locator('iframe')).toHaveAttribute('sandbox', 'allow-same-origin');
      await expect(panel.frameLocator('iframe').locator(`.yk-blox-${area}`)).toContainText(`TB ${area} active`);
      await expect(panel.frameLocator('iframe').locator('script, iframe, object, embed')).toHaveCount(0);
    }
    expect(snapshotRequests).toHaveLength(1);
    expect((await snapshotRequests[0].allHeaders()).cookie).toBeUndefined();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({ path: info.outputPath('published-previews.png'), fullPage: true });
    const preview = new URL(await page.getByTestId('site-design-context-preview').getAttribute('href'), page.url()).href;
    await front.goto(preview);
    await expect(front.locator('.yk-blox-header')).toContainText('TB header active');
    await expect(front.locator('.yk-blox-footer')).toContainText('TB footer active');
    await expect(front.locator('#ik-adminbar, #ik-draft-previewbar')).toHaveCount(0);

    fixture('draft-hide');
    await page.reload();
    await expect(page.getByTestId('site-design-area-header').getByTestId('site-design-area-source')).toContainText('TB header active');
    await front.reload();
    await expect(front.locator('.yk-blox-header')).toBeVisible();

    fixture('publish-hide');
    await page.reload();
    for (const area of ['header', 'footer']) {
      const row = page.getByTestId(`site-design-area-${area}`);
      await expect(row.getByTestId('site-design-area-source')).toHaveText(/隐藏|Hidden|非表示/);
      await expect(row.getByTestId('site-design-area-edit')).toHaveCount(0);
      await expect(row.locator('[data-site-area-preview]')).toHaveCount(0);
      await expect(row.getByTestId('site-design-hidden-page-edit')).toHaveAttribute('href', new RegExp(`id=${state.page}&`));
    }
    await front.reload();
    await expect(front.locator('.yk-blox-header, .yk-blox-footer')).toHaveCount(0);
    await expect(front.getByRole('heading', { name: 'TB page content', exact: true })).toBeVisible();
    await page.screenshot({ path: info.outputPath('published-hidden.png'), fullPage: true });
  } finally {
    try { await visitor.close(); } finally { fixture('restore'); }
  }
});

test('overview falls back to the theme when the global area is disabled @ci', async ({ page }) => {
  const state = JSON.parse(fixture('seed'));
  try {
    fixture('disable');
    await page.goto(`/admin/site_design.php?context=page%3A${state.page}`);
    for (const area of ['header', 'footer']) {
      const row = page.getByTestId(`site-design-area-${area}`);
      await expect(row.getByTestId('site-design-area-source')).toHaveText(/主题默认|Theme default|テーマ/);
      await expect(row.getByTestId('site-design-area-source')).not.toContainText(`TB ${area} active`);
      const panel = row.locator('[data-site-area-preview]');
      await panel.locator('summary').click();
      await expect(panel).toHaveAttribute('data-preview-ready', 'true');
      await expect(panel.frameLocator('iframe').locator(area === 'header' ? '#siteHeader' : 'footer')).toHaveCount(1);
      const edit = row.getByTestId('site-design-area-edit');
      if (await edit.count()) {
        expect(new URL(await edit.getAttribute('href'), page.url()).searchParams.get('template'))
          .not.toBe(String(state[`${area}_active`]));
      }
    }
  } finally {
    fixture('restore');
  }
});

test('static preview reports a missing area and permits retry @ci', async ({ page }) => {
  const state = JSON.parse(fixture('seed'));
  try {
    await page.goto(`/admin/site_design.php?context=page%3A${state.page}`);
    const url = new URL(await page.getByTestId('site-design-context-preview').getAttribute('href'), page.url()).href;
    await page.route(url, route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body>Unavailable</body></html>' }));
    const panel = page.locator('[data-site-area-preview="header"]');
    await panel.locator('summary').click();
    await expect(panel.locator('[data-preview-status]')).toHaveText(/无法预览|cannot be previewed|プレビューできません/);
    await expect(panel.locator('iframe')).toHaveCount(0);
    await page.unroute(url);
    await panel.locator('summary').click();
    await panel.locator('summary').click();
    await expect(panel).toHaveAttribute('data-preview-ready', 'true');
  } finally {
    fixture('restore');
  }
});

for (const mode of ['empty', 'sections-hidden', 'invalid', 'conditional']) {
  test(`unusable ${mode} publication retains its repair link without claiming active use @ci`, async ({ page, browser }, info) => {
    const state = JSON.parse(fixture('seed'));
    const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      fixture(mode);
      await page.goto(`/admin/site_design.php?context=page%3A${state.page}`);
      for (const area of ['header', 'footer']) {
        const row = page.getByTestId(`site-design-area-${area}`);
        if (mode === 'conditional') {
          await expect(row.getByTestId('site-design-area-source')).toContainText(/规则选中|Selected by rule|ルールで選択/);
          await expect(row.getByTestId('site-design-area-source')).not.toContainText(/使用中|In use/);
          await expect(row.getByTestId('site-design-area-reason')).toContainText(/访问条件|visitor conditions|閲覧条件/);
        } else {
          await expect(row.getByTestId('site-design-area-source')).toHaveText(/主题默认|Theme default|テーマ/);
          await expect(row.getByTestId('site-design-area-source')).not.toContainText(`TB ${area} active`);
          await expect(row.getByTestId('site-design-area-reason')).toContainText(`TB ${area} active`);
          const repair = new URL(await row.getByTestId('site-design-area-repair').getAttribute('href'), page.url());
          expect(repair.searchParams.get('template')).toBe(String(state[`${area}_active`]));
          expect(repair.searchParams.get('preview_context')).toBe(`page:${state.page}`);
        }
      }
      // Invalid JSON is checked in the dashboard only; requesting it on the frontend
      // deliberately writes a runtime error log, unlike a valid empty document.
      if (mode !== 'invalid') {
        const front = await visitor.newPage();
        await front.goto(await page.getByTestId('site-design-context-preview').getAttribute('href'));
        await expect(front.locator('#ik-adminbar, #ik-draft-previewbar')).toHaveCount(0);
        await expect(front.locator('.yk-blox-header, .yk-blox-footer')).toHaveCount(0);
        await expect(front.locator('#siteHeader')).toBeVisible();
        await expect(front.locator('footer')).toBeVisible();
      }
      if (mode === 'empty' || mode === 'conditional') {
        await page.screenshot({ path: info.outputPath('publication-source.png'), fullPage: true });
      }
    } finally {
      try { await visitor.close(); } finally { fixture('restore'); }
    }
  });
}
