const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = action => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'image-editor-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' }) || 'null');

// 图片编辑弹窗：旋转 + 1:1 裁剪 + 替代文字；保存后网址不变、文件按操作改好、原图留存；恢复原图回到 400×200。
test('image editor rotates, crops, saves in place and restores the original @ci', async ({ page }) => {
  test.setTimeout(90000);
  const { id } = fixture('setup');
  try {
    await page.goto('/admin/media.php');
    await page.evaluate(mediaId => window.YkImageEditor.open(mediaId), id);
    const modal = page.locator('#imageEditModal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('[data-ie-size]')).toContainText('400');

    const preview = () => page.waitForResponse(r => r.url().includes('action=preview') && r.status() === 200);
    let loaded = preview();
    await modal.locator('[data-ie-op="rotate:90"]').click();
    await loaded;
    await expect(modal.locator('[data-ie-size]')).toContainText('200 × 400');
    await expect(modal.locator('[data-ie-loading]')).toBeHidden();

    await modal.locator('[data-ie-ratio="1:1"]').click();
    await expect(modal.locator('[data-ie-box]')).toBeVisible();
    loaded = preview();
    await modal.locator('[data-ie-apply-crop]').click();
    await loaded;
    await expect(modal.locator('[data-ie-size]')).toContainText('200 × 200');

    await modal.locator('[data-ie-alt]').fill('Red corner sample');
    const saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/media_edit.php'));
    await modal.getByTestId('image-edit-save').click();
    expect((await (await saved).json()).code).toBe(0);
    let info = fixture('inspect');
    expect([info.w, info.h]).toEqual([200, 200]);
    expect(info.row).toEqual([200, 200]);
    expect(info.alt).toBe('Red corner sample');
    expect(info.altFor).toBe('Red corner sample');
    expect(info.original).toBe(true);

    await page.waitForLoadState('load');
    await page.evaluate(mediaId => window.YkImageEditor.open(mediaId), id);
    await expect(modal.getByTestId('image-edit-restore')).toBeVisible();
    page.once('dialog', d => d.accept());
    const restored = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/media_edit.php'));
    await modal.getByTestId('image-edit-restore').click();
    expect((await (await restored).json()).code).toBe(0);
    info = fixture('inspect');
    expect([info.w, info.h]).toEqual([400, 200]);
    expect(info.original).toBe(false);
  } finally {
    fixture('restore');
  }
});
