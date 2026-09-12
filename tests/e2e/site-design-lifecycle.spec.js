const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { addTemporaryHeading, frame, waitPreviewSettled } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'site-design-state-fixture.php'), action, String(id)], { cwd: root, encoding: 'utf8' });

async function command(page, action, button, confirmConflict = false) {
  if (!await page.getByTestId(button).isVisible()) {
    await page.getByTestId('blox-mobile-canvas-view').click();
    await page.getByTestId('blox-mobile-actions-open').click();
    button = button.replace('blox-', 'blox-mobile-');
  }
  const matches = r => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === action;
  const conflict = confirmConflict ? page.waitForResponse(r => matches(r) && r.status() === 409) : null;
  const response = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && matches(r) && (!confirmConflict || new URLSearchParams(r.request().postData() || '').get('confirm_conflict') === '1'));
  await page.getByTestId(button).click();
  if (conflict) {
    const result = await (await conflict).json();
    expect(result.code).toBe(409);
    expect(result.msg).toBeTruthy();
  }
  expect((await (await response).json()).code).toBe(0);
}

for (const area of ['header', 'footer']) {
 for (const mode of ['blank', 'copy']) {
  test(`${area} ${mode} dedicated design survives create edit reopen publish disable and restore @ci`, async ({ page, browser, expectedHttpErrors }, info) => {
    test.setTimeout(90000);
    const state = JSON.parse(fixture('seed'));
    const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const front = await visitor.newPage();
    const frontErrors = [];
    front.on('pageerror', error => frontErrors.push(error.message));
    front.on('console', message => { if (message.type() === 'error') frontErrors.push(message.text()); });
    front.on('response', response => { if (response.status() >= 500) frontErrors.push(`${response.status()} ${response.url()}`); });
    const context = `page:${state.page}`;
    const overview = `/admin/site_design.php?context=${encodeURIComponent(context)}`;
    page.on('dialog', dialog => dialog.accept());
    try {
      fixture('lifecycle', mode);
      await page.goto(overview);
      const publicUrl = await page.getByTestId('site-design-context-preview').getAttribute('href');
      await page.getByTestId(`site-design-area-${area}`).locator('a[href*="blox_templates.php"]').click();
      const row = page.getByTestId('blox-assignment-row').filter({ has: page.locator(`input[name="context"][value="${context}"]`) });
      const create = row.locator('form').filter({ has: page.locator(`input[name="area"][value="${area}"]`) });
      await expect(create.locator('input[name="source_id"]')).toHaveValue(mode === 'copy' ? String(state[`${area}_active`]) : '0');
      await create.getByTestId('blox-assignment-copy-dedicated').click();
      await expect(page).toHaveURL(/blox_editor\.php\?template=\d+/);
      const editor = page.url();
      const id = new URL(editor).searchParams.get('template');
      fixture('track', id);
      expect(new URL(editor).searchParams.get('preview_context')).toBe(context);
      await addTemporaryHeading(page);
      const marker = `Lifecycle ${area} ${info.project.name}`;
      const input = page.locator('[data-control-key="text"] input[type="text"]').first();
      await input.fill(marker);
      await waitPreviewSettled(page);
      await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();
      await command(page, 'save_draft', 'blox-save');

      await front.goto(publicUrl);
      await expect(front.locator('#ik-adminbar, #ik-draft-previewbar')).toHaveCount(0);
      await expect(front.locator('body')).not.toContainText(marker);
      await page.goto(overview);
      const areaRow = page.getByTestId(`site-design-area-${area}`);
      const activeEdit = areaRow.getByTestId('site-design-area-edit');
      if (await activeEdit.count()) expect(new URL(await activeEdit.getAttribute('href'), page.url()).searchParams.get('template')).not.toBe(id);
      await areaRow.locator('a[href*="blox_templates.php"]').click();
      await create.getByTestId('blox-assignment-continue-draft').click();
      await expect(page).toHaveURL(url => url.origin + url.pathname + url.search === editor);
      await waitPreviewSettled(page);
      await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();
      if (mode === 'copy') expectedHttpErrors.push({
        url: new URL('/admin/blox_template_api.php', page.url()).href,
        status: 409, method: 'POST', action: 'publish',
      });
      await command(page, 'publish', 'blox-publish-template', mode === 'copy');
      await front.reload();
      await expect(front.locator(`.yk-blox-${area}`)).toContainText(marker);
      await expect(front.locator('#ik-adminbar, #ik-draft-previewbar')).toHaveCount(0);
      await page.goto(overview);
      expect(new URL(await areaRow.getByTestId('site-design-area-edit').getAttribute('href'), page.url()).searchParams.get('template')).toBe(id);
      await areaRow.getByTestId('site-design-area-edit').click();
      await waitPreviewSettled(page);
      await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();

      await page.goto(`/admin/blox_templates.php?type=${area}&context=${encodeURIComponent(context)}`);
      await page.getByTestId(`blox-custom-${area}-choice-theme`).click();
      await expect(page.getByTestId(`blox-custom-${area}-choice-theme`)).toHaveAttribute('aria-pressed', 'true');
      await front.reload();
      await expect(front.locator('body')).not.toContainText(marker);
      await page.getByTestId(`blox-custom-${area}-choice-custom`).click();
      await expect(page.getByTestId(`blox-custom-${area}-choice-custom`)).toHaveAttribute('aria-pressed', 'true');
      await front.reload();
      await expect(front.locator(`.yk-blox-${area}`)).toContainText(marker);
      await front.screenshot({ path: info.outputPath(`${area}-restored.png`), fullPage: true });

      const dedicated = page.getByTestId('blox-assignment-row').filter({
        has: page.locator(`input[name="context"][value="${context}"]`),
      }).locator(`[data-area="${area}"]`);
      await dedicated.getByTestId('blox-assignment-restore-inherit').click();
      await expect(dedicated.getByTestId('blox-assignment-continue-draft')).toBeVisible();
      await front.reload();
      await expect(front.locator('body')).not.toContainText(marker);
      if (mode === 'copy') await expect(front.locator(`.yk-blox-${area}`)).toContainText(`TB ${area} active`);
      await dedicated.getByTestId('blox-assignment-continue-draft').click();
      await expect(page).toHaveURL(url => url.origin + url.pathname + url.search === editor);
      await waitPreviewSettled(page);
      await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();
      expect(frontErrors, 'Anonymous page has no browser or server errors').toEqual([]);
    } finally {
      try {
        await visitor.close();
      } finally {
        fixture('restore');
      }
    }
  });
 }
}
