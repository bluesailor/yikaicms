const path = require('path');
const { defineConfig } = require('@playwright/test');

const baseURL = process.env.BLOX_E2E_BASE_URL || 'http://127.0.0.1:8080';
const storageState = process.env.BLOX_E2E_STORAGE_STATE
  || path.join(__dirname, 'tests/e2e/.auth/admin.json');
const outputDir = process.env.BLOX_E2E_OUTPUT_DIR || 'test-results/e2e';
const reportDir = process.env.BLOX_E2E_REPORT_DIR || 'playwright-report';
const specFilter = String(process.env.BLOX_E2E_SPEC_FILTER || '').trim();
// 分片 runner 用这份机器可读结果判断「这一 phase 真的跑过用例」——全跳过不算通过。
const jsonReport = String(process.env.BLOX_E2E_JSON_REPORT || '').trim();
const specFilterPattern = specFilter
  ? new RegExp(`(?:^|[\\\\/])${specFilter.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`)
  : undefined;

module.exports = defineConfig({
  testDir: './tests/e2e',
  // A shard phase requests one file through the disposable site's own test
  // directory; do not pass a source-worktree path to Playwright.
  testMatch: specFilterPattern,
  fullyParallel: false,
  workers: 1,
  timeout: 45_000,
  expect: { timeout: 8_000 },
  globalSetup: require.resolve('./tests/e2e/global-setup'),
  outputDir,
  reporter: [
    ['list'],
    ['html', { outputFolder: reportDir, open: 'never' }],
    ...(jsonReport ? [['json', { outputFile: jsonReport }]] : []),
  ],
  // 截图基线按平台分套：CI(Linux) 与本地(Windows) 字体渲染有 1-2px 尺寸差，
  // 单套基线会让另一平台永远红。新平台首跑用 --update-snapshots 生成本套基线。
  snapshotPathTemplate: '{testDir}/{testFilePath}-snapshots/{arg}-{projectName}-{platform}{ext}',
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
