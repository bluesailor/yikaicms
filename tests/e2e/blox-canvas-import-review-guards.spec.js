const { test, expect } = require('@playwright/test');
const { openPageEditor, countSections } = require('./helpers');

/**
 * 画布插入检查对话框的两处竞态（评审 P2-01 / P2-02，2026-09-17 修复）：
 * - 确认请求在途时按取消 / Esc，结果返回后仍把模板插进画布；
 * - 确认成功但插入失败时，对话框先被关掉，错误无处显示。
 */
async function mockRemoteTemplate(page, confirmTemplate, confirmDelayMs = 0) {
  await page.route('**/admin/blox_template_api.php?action=list**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ code: 0, msg: 'ok', data: { items: [{
      key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
      provider: 'update.yikaicms.com', thumbnail: '', locked: false,
    }], remote_error: '' } }),
  }));
  await page.route('**/admin/blox_template_api.php*', async (route) => {
    const body = route.request().postData() || '';
    if (body.includes('action=prepare_insert')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, msg: 'ok', data: {
          template: { key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
            requirements: { design_tokens: [], design_styles: [] } },
          review_id: 'e2eguard000000000000000000000000',
          design_diagnostics: { missing_tokens: [], missing_styles: [] },
        } }),
      });
      return;
    }
    if (body.includes('action=confirm_insert')) {
      if (confirmDelayMs) await new Promise((resolve) => setTimeout(resolve, confirmDelayMs));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, msg: 'ok', data: { template: confirmTemplate } }),
      });
      return;
    }
    await route.fallback();
  });
}

async function openReview(page) {
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await openPageEditor(page, fixtures.process_page);
  const before = await countSections(page);
  await page.getByTestId('blox-prebuilt-open').click();
  await page.getByTestId('blox-template-tab-remote').click();
  await expect(page.getByTestId('blox-template-insert')).toBeVisible();
  await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    app.insertTemplateAt(app.templateItems.find((item) => item.key === 'remote:hero'), app.sections.length);
  });
  const dialog = page.getByTestId('blox-template-review-dialog');
  await expect(dialog).toBeVisible();
  return { before, dialog };
}

const heroTemplate = {
  key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote', settings: {},
  sections: [{ id: 'tpl_guard_s_0', type: 'section', settings: {},
    columns: [{ id: 'tpl_guard_c_0', span: 12, elements: [{ id: 'tpl_guard_e_0', type: 'heading', data: { text: 'Guarded hero' } }] }] }],
};

test('review dialog cannot be cancelled while the confirm request is in flight @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await mockRemoteTemplate(page, heroTemplate, 1500);
  const { before, dialog } = await openReview(page);

  await page.getByTestId('blox-template-review-confirm').click();
  const cancel = page.getByTestId('blox-template-review-cancel');
  await expect(cancel).toBeDisabled();
  await page.keyboard.press('Escape');
  await page.evaluate(() => window.Alpine.$data(document.body).cancelTemplateReview());
  await expect(dialog).toBeVisible();

  // 结果返回：恰好插入一次，对话框关闭
  await expect(dialog).toBeHidden();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await page.getByTestId('blox-undo').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
});

test('failed insert after a successful confirm keeps the dialog open with the error @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await mockRemoteTemplate(page, { ...heroTemplate, sections: [] });
  const { before, dialog } = await openReview(page);

  await page.getByTestId('blox-template-review-confirm').click();
  await expect.poll(() => page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.templateReview ? app.templateReview.error : null;
  })).toBe(await page.evaluate(() => window.Alpine.$data(document.body).templateText.insertFailed));
  await expect(dialog).toBeVisible();
  await expect(page.getByTestId('blox-template-review-confirm')).toBeEnabled();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await page.getByTestId('blox-template-review-cancel').click();
  await expect(dialog).toBeHidden();
});
