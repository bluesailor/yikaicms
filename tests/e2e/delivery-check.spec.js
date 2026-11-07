const { test, expect } = require('./site-diagnostics');

// 交付检查清单（T09）：从建站向导进入；三块分开；运行检查后有结果；确认与撤销都记下来
test('delivery checklist separates content, checks and confirmations @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(120000);
  const dialogs = [];
  page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/admin/site_setup.php');
  await page.getByTestId('setup-delivery-link').click();
  await expect(page).toHaveURL(/delivery_check\.php/);
  for (const group of ['data', 'check', 'accept']) await expect(page.getByTestId('delivery-' + group)).toBeVisible();
  await expect(page.locator('[data-testid="delivery-check"] li[data-status="unknown"]')).toHaveCount(2);

  await page.getByTestId('delivery-run').click();
  await expect(page).toHaveURL(/delivery_check\.php$/);
  await expect(page.locator('[data-testid="delivery-check"] li[data-status="unknown"]')).toHaveCount(0);

  const inquiry = page.locator('[data-testid="delivery-accept"] li[data-item="inquiry_test"]');
  await expect(inquiry).toHaveAttribute('data-status', 'todo');
  await page.getByTestId('delivery-accept-inquiry_test').click();
  await expect(inquiry).toHaveAttribute('data-status', 'done');
  await expect(inquiry).toContainText(/admin/);
  await page.reload();
  await expect(inquiry).toHaveAttribute('data-status', 'done');
  await expect(inquiry).toContainText(/admin/);
  await info.attach('delivery-checklist', { body: await page.locator('main, .max-w-4xl').first().screenshot(), contentType: 'image/png' });
  await inquiry.getByRole('button').click();
  await expect(inquiry).toHaveAttribute('data-status', 'todo');
  await page.reload();
  await expect(inquiry).toHaveAttribute('data-status', 'todo');
  await expect(page.locator('[data-testid="delivery-check"] li[data-status="unknown"]')).toHaveCount(0);
  expect(dialogs, 'The workflow must not open native confirmation dialogs').toEqual([]);
});
