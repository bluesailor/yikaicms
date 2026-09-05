const { test, expect } = require('@playwright/test');
const {
  countSections,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  undo,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;
let recoveryKey;

const recoveredDocument = JSON.stringify({
  schema: 1,
  settings: {},
  sections: [{
    id: 'recovered-section',
    type: 'section',
    settings: { title: 'E2E recovery' },
    columns: [{ id: 'recovered-column', span: 12, elements: [] }],
  }],
});

function attribute(html, name) {
  const match = html.match(new RegExp(`${name}="([^"]+)"`));
  return match ? match[1] : '';
}

async function installRecoveryBeforeNavigation(page) {
  const response = await page.request.get('/admin/blox_editor.php?home=1');
  expect(response.ok()).toBe(true);
  const html = await response.text();
  recoveryKey = attribute(html, 'data-blox-recovery-key');
  const baseRevision = attribute(html, 'data-blox-base-revision');
  expect(recoveryKey).toBeTruthy();
  expect(baseRevision).toMatch(/^[a-f0-9]{64}$/);

  await page.addInitScript(({ storageKey, revision, data }) => {
    if (window !== window.top) return;
    localStorage.setItem(storageKey, JSON.stringify({
      version: 1,
      savedAt: Date.now() + 60000,
      baseRevision: revision,
      data,
    }));
  }, { storageKey: recoveryKey, revision: baseRevision, data: recoveredDocument });
}

test.beforeEach(async ({ page }, testInfo) => {
  consoleEntries = null;
  unsafeWrites = null;
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop recovery baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await installRecoveryBeforeNavigation(page);
  await openEditor(page);
  await expect(page.getByTestId('blox-recovery-dialog')).toBeVisible();
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  expect(await page.getByTestId('blox-dirty').isVisible().catch(() => false), 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'save/publish/rollback request was sent').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('local recovery draft can be explicitly discarded @ci @shard-core', async ({ page }) => {
  await expect(page.getByTestId('blox-recovery-restore')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('blox-recovery-dialog')).toBeVisible();

  await page.getByTestId('blox-recovery-discard').click();
  await expect(page.getByTestId('blox-recovery-dialog')).toBeHidden();
  expect(await page.evaluate((storageKey) => localStorage.getItem(storageKey), recoveryKey)).toBeNull();
});

test('local recovery draft can be restored and undone @ci @shard-core', async ({ page }) => {
  const originalCount = await countSections(page);
  await page.getByTestId('blox-recovery-restore').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(1);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();

  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(originalCount);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
  await expect.poll(() => page.evaluate((storageKey) => localStorage.getItem(storageKey), recoveryKey)).toBeNull();
});
