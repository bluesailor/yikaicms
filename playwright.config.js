const path = require('path');
const { defineConfig } = require('@playwright/test');

const baseURL = process.env.BLOX_E2E_BASE_URL || 'http://127.0.0.1:8080';
const storageState = path.join(__dirname, 'tests/e2e/.auth/admin.json');

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 8_000 },
  globalSetup: require.resolve('./tests/e2e/global-setup'),
  outputDir: 'test-results/e2e',
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],
  use: {
    baseURL,
    storageState,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop-1440', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'tablet-768', use: { viewport: { width: 768, height: 900 } } },
    { name: 'mobile-390', use: { viewport: { width: 390, height: 844 } } },
  ],
});
