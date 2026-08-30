const { openEditor } = require('./helpers');

async function openBanner(page) {
  await openEditor(page);
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  const banner = page.locator('[data-testid="blox-tree-element"][data-home-block-type="banner"]').first();
  await banner.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]').locator('[data-section-drag-handle]').first().click();
  if (await structure.isVisible()) await structure.click();
  await banner.locator('[data-element-drag-handle]').click();
}

module.exports = { openBanner };
