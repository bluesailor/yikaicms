const path = require('node:path');
const fs = require('node:fs/promises');
const {test, expect} = require('@playwright/test');

const root = path.resolve(__dirname, '../..');

async function mountCandidateGrid(page) {
  await page.setContent(`<!doctype html><html><head><style>
    .im-random-candidates { display:grid;grid-template-columns:repeat(4,120px);gap:12px;padding:20px }
    .im-random-candidate { height:120px;border:1px solid #ccd3da;background:#fff }
    .im-random-drag-handle { width:36px;height:36px;cursor:grab;touch-action:none }
    .im-random-sort-ghost { opacity:.3 }
  </style></head><body>
    <div class="im-random-lab">
      <form id="imRandomForm"></form>
      <div class="im-random-candidates" data-im-random-candidates data-im-order-message="Option :n moved to :position">
        ${[0, 1, 2, 3].map((id) => `<article class="im-random-candidate" data-im-random-candidate="${id}"><button class="im-random-drag-handle" type="button">${id}</button></article>`).join('')}
      </div>
      <p data-im-order-status aria-live="polite"></p>
    </div>
  </body></html>`);
  await page.addScriptTag({path: path.join(root, 'assets/sortable/Sortable.min.js')});
  await page.addScriptTag({path: path.join(root, 'plugins/icon-maker/random-order.js')});
  await page.addScriptTag({path: path.join(root, 'plugins/icon-maker/random-logo.js')});
}

async function candidateIds(page) {
  return page.locator('[data-im-random-candidate]').evaluateAll((cards) => cards.map((card) => card.dataset.imRandomCandidate));
}

test('random icon candidates support pointer and keyboard reordering', async ({page}) => {
  await mountCandidateGrid(page);

  const source = page.locator('.im-random-drag-handle').first();
  const target = page.locator('[data-im-random-candidate="3"]');
  const sourceBox = await source.boundingBox();
  const targetBox = await target.boundingBox();
  expect(sourceBox).not.toBeNull();
  expect(targetBox).not.toBeNull();

  await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, {steps: 12});
  await page.waitForTimeout(100);
  await page.mouse.up();

  await expect.poll(() => candidateIds(page)).not.toEqual(['0', '1', '2', '3']);
  await expect(page.locator('[data-im-order-status]')).not.toHaveText('');

  const optionOneHandle = page.locator('[data-im-random-candidate="1"] .im-random-drag-handle');
  await optionOneHandle.focus();
  await optionOneHandle.press('End');
  await expect.poll(async () => (await candidateIds(page)).at(-1)).toBe('1');
  await expect(optionOneHandle).toBeFocused();
});

test('logo editor exports upright vertical text @local', async ({page}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single export baseline');

  await page.goto('/admin/plugin.php');
  const pluginCard = page.locator('#plugin-icon-maker');
  const activate = pluginCard.locator('button[onclick*="pluginAction(\'activate\'"]');
  if (await activate.isVisible()) {
    await activate.click();
    await expect(pluginCard.getByRole('link')).toBeVisible();
  }
  await page.goto('/admin/plugin_page.php?plugin=icon-maker#logo');
  const stage = page.locator('#imLogoStage');
  await expect(stage).toBeVisible();
  await page.locator('#imLText').fill('竖排');

  const vertical = page.getByTestId('iconmaker-text-vertical');
  await vertical.click();
  await expect(vertical).toHaveAttribute('aria-pressed', 'true');
  await expect(stage).toHaveAttribute('data-text-orientation', 'vertical');

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('#imLogoSvg').click(),
  ]);
  const svg = await fs.readFile(await download.path(), 'utf8');
  expect(svg).toContain('>竖</text>');
  expect(svg).toContain('>排</text>');
  expect(svg).not.toContain('>竖排</text>');
  expect(svg).toContain('text-anchor="middle"');
  expect(svg).not.toMatch(/transform="rotate\([^)]*\)"[^>]*>竖排/);

  const horizontal = page.getByTestId('iconmaker-text-horizontal');
  await horizontal.click();
  await expect(horizontal).toHaveAttribute('aria-pressed', 'true');
  await expect(stage).toHaveAttribute('data-text-orientation', 'horizontal');
});
