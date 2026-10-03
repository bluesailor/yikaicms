// 2.0.4 页面切换动画：模板设置里的全站开关写入前台头部，随动效强度降级，伪造值整单拒绝。
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('page transition setting reaches the front end and follows the motion level @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'serialized settings');
  const errors = observeConsole(page);
  await page.goto('/admin/theme.php?tab=settings');
  await expect(page.getByTestId('page-transition')).toHaveValue('none');
  const settings = await page.getByTestId('theme-settings-panel').locator('form').evaluate(el => new URLSearchParams(new FormData(el)).toString());
  const save = async (values) => {
    const form = new URLSearchParams(settings);
    Object.entries(values).forEach(([key, value]) => form.set(key, value));
    await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(form) });
  };
  const front = await page.context().newPage();
  try {
    await front.goto('/');
    await expect(front.locator('style[data-yk-page-transition]')).toHaveCount(0);

    await save({ page_transition: 'slide', motion_intensity: 'standard' });
    await page.goto('/admin/theme.php?tab=settings');
    await expect(page.getByTestId('page-transition')).toHaveValue('slide');
    await front.goto('/');
    const style = front.locator('style[data-yk-page-transition]');
    await expect(style).toHaveAttribute('data-yk-page-transition', 'slide');
    expect(await style.textContent()).toContain('@view-transition{navigation:auto}');
    // 站内跳转照常完成（支持的浏览器播放过渡，不支持的直接跳转）
    const link = front.locator('header a[href]:not([href^="http"]):not([href^="#"])').first();
    if (await link.count()) {
      const href = await link.getAttribute('href');
      await link.click();
      await expect(front).toHaveURL(new RegExp(href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));
    }

    await save({ page_transition: 'slide', motion_intensity: 'light' });
    await front.goto('/');
    await expect(front.locator('style[data-yk-page-transition]')).toHaveAttribute('data-yk-page-transition', 'fade');

    await save({ page_transition: 'slide', motion_intensity: 'none' });
    await front.goto('/');
    await expect(front.locator('style[data-yk-page-transition]')).toHaveCount(0);

    await save({ page_transition: '</style><script>', motion_intensity: 'standard' });
    await page.goto('/admin/theme.php?tab=settings');
    // 伪造值整单拒绝：保持上一次保存的值（动效强度也没被改成 standard）
    await expect(page.getByTestId('page-transition')).toHaveValue('slide');
    await expect(page.getByTestId('motion-intensity')).toHaveValue('none');
    expect(errors.filter(e => /pageerror|Alpine Expression Error/.test(e))).toEqual([]);
  } finally {
    await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(new URLSearchParams(settings)) });
    await front.close();
  }
});
