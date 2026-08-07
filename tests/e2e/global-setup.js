const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

module.exports = async function globalSetup(config) {
  const project = config.projects[0];
  const baseURL = String(project.use.baseURL || '');
  let parsed;
  try {
    parsed = new URL(baseURL);
  } catch (error) {
    throw new Error(`Blox E2E baseURL is invalid: ${baseURL}`);
  }
  if (parsed.protocol !== 'http:' || parsed.hostname !== '127.0.0.1') {
    throw new Error(`Blox E2E refuses non-local baseURL: ${baseURL}`);
  }

  const storageState = String(project.use.storageState || '');
  if (!storageState) throw new Error('Blox E2E storageState is not configured');
  fs.mkdirSync(path.dirname(storageState), { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  try {
    await page.goto('/admin/login.php', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="username"]').fill('admin');
    await page.locator('input[name="password"]').fill('smoke@Test123');
    await Promise.all([
      page.waitForURL((url) => !url.pathname.endsWith('/admin/login.php')),
      page.locator('button[type="submit"]').click(),
    ]);
    await context.storageState({ path: storageState });
  } finally {
    await browser.close();
  }
};
