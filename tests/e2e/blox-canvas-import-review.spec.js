const { test, expect } = require('@playwright/test');
const { openPageEditor, countSections, performPagePreviewUpdate } = require('./helpers');

// 画布插入远程模板的检查-确认两段式：确认前不动文档，映射选择随确认提交，
// 插入一次可整组撤销。服务端评审协议由 PHPUnit 覆盖，这里 mock 编辑器 API。
for (const scenario of ['tail', 'empty', 'edited', 'deleted']) {
test(`canvas remote insert preserves review context: ${scenario} @ci`, async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));

  const confirmBodies = [];
  await page.route('**/admin/blox_template_api.php?action=list**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: 'ok',
        data: {
          items: [{
            key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
            provider: 'update.yikaicms.com', thumbnail: '', locked: false,
          }],
          remote_error: '',
        },
      }),
    });
  });
  await page.route('**/admin/blox_template_api.php*', async (route) => {
    const body = route.request().postData() || '';
    if (body.includes('action=prepare_insert')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          msg: 'ok',
          data: {
            template: {
              key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
              requirements: { design_tokens: ['remote'], design_styles: [] },
            },
            review_id: 'e2ereview0000000000000000000000',
            design_diagnostics: { missing_tokens: ['remote'], missing_styles: [] },
          },
        }),
      });
      return;
    }
    if (body.includes('action=confirm_insert')) {
      confirmBodies.push(body);
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          msg: 'ok',
          data: {
            template: {
              key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
              settings: {},
              sections: [{
                id: 'tpl_e2e_s_0', type: 'section', settings: {},
                columns: [{ id: 'tpl_e2e_c_0_0', span: 12, elements: [{ id: 'tpl_e2e_e_0_0_0', type: 'heading', data: { text: 'Mapped hero' } }] }],
              }],
            },
          },
        }),
      });
      return;
    }
    await route.fallback();
  });

  await openPageEditor(page, fixtures.process_page);
  if (scenario === 'empty') {
    await page.evaluate(() => {
      const app = window.Alpine.$data(document.body);
      app.runCommand('test-empty-canvas', () => app.sections.splice(0));
    });
  }
  const before = await countSections(page);

  await page.getByTestId('blox-prebuilt-open').click();
  await page.getByTestId('blox-template-tab-remote').click();
  await expect(page.getByTestId('blox-template-insert')).toBeVisible();
  // Invoke the same explicit boundary action as the trailing canvas insertion rail.
  await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    app.insertTemplateAt(app.templateItems.find(item => item.key === 'remote:hero'), app.sections.length);
  });

  // 检查模态出现：诊断可见、映射下拉可选；文档尚未插入。
  const reviewDialog = page.locator('[data-testid="blox-template-review-dialog"]');
  await expect(reviewDialog).toBeVisible();
  await expect(reviewDialog).toContainText('remote');
  const tokenSelect = reviewDialog.locator('select[data-testid="blox-template-review-map-tokens"]');
  await expect(tokenSelect).toHaveCount(1);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);

  await tokenSelect.selectOption('primary');
  if (scenario === 'edited' || scenario === 'deleted') {
    await page.evaluate((mode) => {
      const app = window.Alpine.$data(document.body);
      app.runCommand('test-concurrent-edit', () => {
        if (mode === 'deleted') app.sections.pop();
        else app.sections[0].name = 'Edited while waiting for import';
      });
    }, scenario);
  }
  await page.getByTestId('blox-template-review-confirm').click();

  if (scenario === 'edited' || scenario === 'deleted') {
    await expect.poll(() => page.evaluate(() => {
      const app = window.Alpine.$data(document.body);
      return app.templateError === app.templateText.reviewContextChanged;
    })).toBe(true);
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(before - (scenario === 'deleted' ? 1 : 0));
    expect(await page.evaluate(() => window.Alpine.$data(document.body).recentTemplateKeys)).not.toContain('remote:hero');
    return;
  }

  // 确认后一次插入整组区块。
  await expect(reviewDialog).toBeHidden();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect(page.locator('[data-testid="blox-template-dialog"]')).toBeHidden();

  // 确认请求只带 review_id + 映射，且映射值随选择提交。
  expect(confirmBodies).toHaveLength(1);
  expect(confirmBodies[0]).toContain('review_id=e2ereview0000000000000000000000');
  expect(confirmBodies[0]).toContain('design_tokens%5Bremote%5D=primary');

  // 一次撤销还原整次导入。
  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
});
}
