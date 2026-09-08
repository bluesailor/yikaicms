const { test, expect } = require('./site-diagnostics');

// Explicit opt-in only: the runner's spec filter selects this non-spec file.
// A successful probe produces a failing test run, proving the error gate works.
test('network failure must fail diagnostics @diagnostics', async ({ page }) => {
  await page.goto('/admin/login.php');
  await page.route('**/e2e-network-probe.css', route => route.abort('failed'));
  const failed = page.waitForEvent('requestfailed', request => request.url().endsWith('/e2e-network-probe.css'));
  await page.evaluate(() => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/e2e-network-probe.css';
    document.head.appendChild(link);
  });
  expect((await failed).failure().errorText).toBe('net::ERR_FAILED');
});

test('cancelled request is recorded without failing diagnostics @cancellation', async ({ page }) => {
  await page.goto('/admin/login.php');
  await page.route('**/e2e-cancel-probe.css', route => route.abort('aborted'));
  const failed = page.waitForEvent('requestfailed', request => request.url().endsWith('/e2e-cancel-probe.css'));
  await page.evaluate(() => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/e2e-cancel-probe.css';
    document.head.appendChild(link);
  });
  expect((await failed).failure().errorText).toBe('net::ERR_ABORTED');
});
