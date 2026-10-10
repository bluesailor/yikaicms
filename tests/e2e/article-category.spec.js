const { test, expect } = require('./site-diagnostics');

// 文章分类管理页（2.0.5）：从文章列表的标签进入，新增 / 编辑 / 显示开关 / 删除；下载模块同样用标签切换列表与分类。
test('article categories can be managed from the article tabs @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  const name = `E2E 分类 ${Date.now()}`;

  await page.goto('/admin/article.php');
  await page.getByTestId('admin-module-article_category').click();
  await expect(page).toHaveURL(/\/admin\/article_category\.php/);
  await expect(page.getByTestId('article-category-table')).toBeVisible();

  // 新增
  await page.getByTestId('article-category-add').click();
  await page.locator('#editName').fill(name);
  await page.locator('#editForm button[type="submit"]').click();
  const row = page.locator('[data-testid="article-category-table"] tr', { hasText: name });
  await expect(row).toBeVisible();

  // 编辑名称
  await row.getByRole('button', { name: /编辑|Edit/ }).click();
  await page.locator('#editName').fill(`${name} 改`);
  await page.locator('#editForm button[type="submit"]').click();
  const edited = page.locator('[data-testid="article-category-table"] tr', { hasText: `${name} 改` });
  await expect(edited).toBeVisible();

  // 显示开关：启用 → 停用
  const statusBtn = edited.locator('td').nth(4).locator('button');
  const before = (await statusBtn.textContent()).trim();
  await statusBtn.click();
  await expect(statusBtn).not.toHaveText(before);

  // 删除（空分类可删）
  page.once('dialog', (d) => d.accept());
  await edited.getByRole('button', { name: /删除|Delete/ }).click();
  await expect(page.locator('[data-testid="article-category-table"] tr', { hasText: `${name} 改` })).toHaveCount(0);

  // 文章编辑页的「分类管理」链接指向本页
  await page.goto('/admin/article_edit.php');
  await expect(page.getByTestId('article-category-manage')).toHaveAttribute('href', /\/admin\/article_category\.php\?lang=/);

  // 下载：列表与分类两个标签
  await page.goto('/admin/download.php');
  await expect(page.getByTestId('admin-module-download')).toBeVisible();
  await page.getByTestId('admin-module-download_category').click();
  await expect(page).toHaveURL(/\/admin\/download_category\.php/);
});

// 拖动排序（2026-10-11）：只在同一上级的分类之间移动，下级跟着上级走，刷新后顺序保持；跨上级的提交被拒绝。
test('article categories reorder by dragging within the same parent @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  await page.goto('/admin/article_category.php?lang=zh-CN');
  const rows = page.locator('#acatRows tr[data-acat-row]');
  const topLevel = page.locator('#acatRows tr[data-acat-row][data-parent="0"]');
  const ids = async (locator) => locator.evaluateAll((nodes) => nodes.map((n) => n.dataset.id));
  const original = await ids(topLevel);
  expect(original.length).toBeGreaterThanOrEqual(2);
  const post = (body) => page.evaluate(async (pairs) => {
    const fd = new FormData();
    pairs.forEach(([k, v]) => fd.append(k, v));
    return (await fetch('/admin/article_category.php?lang=zh-CN', { method: 'POST', body: fd })).json();
  }, body);
  let childId = '';

  try {
    // 第一个顶层分类下临时建一个下级（种子数据的分类都在顶层），拖动时它要跟着上级走
    const created = await post([['action', 'save'], ['name', `E2E 下级 ${Date.now()}`], ['parent_id', original[0]]]);
    expect(created.code).toBe(0);
    childId = String(created.data.id);
    await page.reload();
    await expect(page.locator(`#acatRows tr[data-id="${childId}"]`)).toHaveAttribute('data-parent', original[0]);
    expect(await ids(topLevel)).toEqual(original);

    // 第一个顶层分类拖到第二个下面
    const first = topLevel.nth(0).getByTestId('article-category-drag');
    const second = topLevel.nth(1);
    const from = await first.boundingBox();
    const to = await second.boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2, to.y + to.height * 0.8, { steps: 12 });
    await page.mouse.up();
    const expected = [original[1], original[0], ...original.slice(2)];
    await expect.poll(() => ids(topLevel)).toEqual(expected);
    await expect(page.locator(`#acatRows tr[data-id="${original[0]}"] [data-acat-sort]`)).toHaveText('1');
    const childFollows = () => rows.evaluateAll((nodes, [parent, child]) => {
      const order = nodes.map((n) => n.dataset.id);
      return order.indexOf(child) === order.indexOf(parent) + 1;
    }, [original[0], childId]);
    await expect.poll(childFollows).toBe(true);

    await page.reload();
    await expect.poll(() => ids(topLevel)).toEqual(expected);
    await expect.poll(childFollows).toBe(true);

    // 跨上级混在一起提交：拒绝
    const mixed = await post([['action', 'sort'], ['ids[]', original[1]], ['ids[]', childId]]);
    expect(mixed.code).not.toBe(0);
  } finally {
    if (childId) await post([['action', 'delete'], ['id', childId]]);
    await post([['action', 'sort'], ...original.map((id) => ['ids[]', id])]);
  }
});
