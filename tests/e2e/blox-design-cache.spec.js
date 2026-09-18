const { test, expect } = require('./site-diagnostics');
const { execFileSync, spawn } = require('child_process');
const path = require('path');

const fixture = (name, action) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, name), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

async function holdCacheFile() {
  const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-File',
    path.join(__dirname, 'blox-cache-lock.ps1')], { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
  let error = '';
  child.stderr.on('data', (data) => { error += data; });
  const completed = new Promise((resolve) => child.once('exit', resolve));
  try {
    await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Cache lock timeout: ' + error)), 10000);
      child.stdout.on('data', (data) => {
        if (data.toString().includes('LOCKED')) { clearTimeout(timeout); resolve(); }
      });
      child.once('error', (err) => { clearTimeout(timeout); reject(err); });
      child.once('exit', (code) => { clearTimeout(timeout); reject(new Error(`Lock exited ${code}: ${error}`)); });
    });
    return async () => { child.stdin.end('\n'); await completed; };
  } catch (error) {
    child.stdin.end('\n');
    await completed;
    throw error;
  }
}

test('publication stops serving an old cache even while Windows refuses to delete it', async ({ page, browser }) => {
  test.skip(process.platform !== 'win32', 'Windows file sharing failure scenario');
  test.setTimeout(90000);
  fixture('catalog-baseline-fixture.php', 'cache-pretty');
  fixture('blox-cache-fault-fixture.php', 'reset');
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  let release;
  try {
    await page.goto('/admin/blox_design.php');
    await page.getByTestId('blox-design-page-tab-theme').click();
    await page.getByTestId('blox-design-page-theme-width').fill('1123');
    await page.getByTestId('blox-design-page-theme-publish').click();
    await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
    const first = await anonymous.request.get('/');
    expect(first.headers()['x-cache']).toBe('MISS');
    expect(await first.text()).toContain('--yk-layout-max-width:1123px');
    expect((await anonymous.request.get('/')).headers()['x-cache']).toBe('HIT');
    release = await holdCacheFile();
    await page.getByTestId('blox-design-page-theme-width').fill('1256');
    await page.getByTestId('blox-design-page-theme-publish').click();
    await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
    const after = await anonymous.request.get('/');
    expect(after.headers()['x-cache']).toBe('MISS');
    expect(await after.text()).toContain('--yk-layout-max-width:1256px');
    const hot = await anonymous.request.get('/');
    expect(hot.headers()['x-cache']).toBe('HIT');
    expect(await hot.text()).toContain('--yk-layout-max-width:1256px');
  } finally {
    if (release) await release();
    fixture('catalog-baseline-fixture.php', 'restore');
    await anonymous.close();
  }
});

test('anonymous design cache stays on published data and survives unusable cache storage', async ({ page, browser }) => {
  test.setTimeout(90000);
  fixture('catalog-baseline-fixture.php', 'cache-pretty');
  fixture('blox-cache-fault-fixture.php', 'reset');
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const front = async (cacheState) => {
    const response = await anonymous.request.get('/');
    expect(response.status()).toBe(200);
    expect(response.headers()['x-cache']).toBe(cacheState);
    const html = await response.text();
    expect(html).toContain('</html>');
    expect(html.length).toBeGreaterThan(1000);
    return html;
  };
  try {
    const cold = await front('MISS');
    expect(await front('HIT')).toBe(cold);
    await page.goto('/admin/blox_design.php');
    await page.getByTestId('blox-design-page-tab-theme').click();
    await page.getByTestId('blox-design-page-theme-width').fill('1234');
    await page.getByTestId('blox-design-page-theme-save').click();
    await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-amber-50/);
    expect(await front('HIT'), 'Draft save must not invalidate the published cache').toBe(cold);
    await page.getByTestId('blox-design-page-theme-publish').click();
    await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
    const published = await front('MISS');
    expect(published).toContain('--yk-layout-max-width:1234px');
    expect(await front('HIT')).toBe(published);
    fixture('blox-cache-fault-fixture.php', 'empty');
    expect(await front('MISS')).toContain('--yk-layout-max-width:1234px');
    fixture('blox-cache-fault-fixture.php', 'block-writes');
    for (let i = 0; i < 2; i++) {
      expect(await front('MISS')).toContain('--yk-layout-max-width:1234px');
    }
  } finally {
    fixture('blox-cache-fault-fixture.php', 'restore');
    fixture('catalog-baseline-fixture.php', 'restore');
    await anonymous.close();
  }
});
