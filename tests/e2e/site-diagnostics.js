const { test: base, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
function logs() {
  const dir = path.join(root, 'storage/logs');
  return Object.fromEntries((fs.existsSync(dir) ? fs.readdirSync(dir) : [])
    .filter(name => /^error-\d{6}\.log$/.test(name))
    .map(name => [name, fs.readFileSync(path.join(dir, name), 'utf8')]));
}
const test = base.extend({
  launchOptions: [async ({ launchOptions }, use, info) => {
    if (process.env.BLOX_E2E_NET_LOG !== '1') return use(launchOptions);
    if (!path.basename(root).startsWith('yikai-e2e-')) throw new Error('NetLog requires a disposable test site');
    const dir = path.join(root, 'storage/e2e-netlog');
    fs.mkdirSync(dir, { recursive: true });
    await use({ ...launchOptions, args: [
      ...(launchOptions.args || []),
      `--log-net-log=${path.join(dir, `worker-${info.workerIndex}.json`)}`,
    ] });
  }, { scope: 'worker', option: true }],
  diagnostics: [async ({ page, baseURL }, use, info) => {
    expect(new URL(baseURL).hostname).toBe('127.0.0.1');
    expect(path.basename(root)).toMatch(/^yikai-e2e-/);
    expect(fs.existsSync(path.join(root, 'storage/.smoke-state-backup/manifest.json'))).toBeTruthy();
    const before = logs(), errors = [], failedRequests = [];
    page.on('requestfailed', request => failedRequests.push({
      at: new Date().toISOString(),
      url: request.url(),
      method: request.method(),
      resourceType: request.resourceType(),
      failure: request.failure()?.errorText || 'Unknown network failure',
    }));
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => {
      if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`);
    });
    await use();
    await info.attach('failed-requests', {
      body: JSON.stringify(failedRequests, null, 2), contentType: 'application/json',
    });
    const after = logs();
    for (const name of Object.keys(before)) expect(after).toHaveProperty(name);
    const added = Object.entries(after).map(([name, value]) => {
      expect(value.startsWith(before[name] || ''), 'Error log must not be cleared during test').toBeTruthy();
      return value.slice((before[name] || '').length);
    }).join('');
    await info.attach('new-php-errors', { body: added || '(none)', contentType: 'text/plain' });
    expect(added, 'No new CMS error log entries').toBe('');
    expect(errors, 'Browser and server errors').toEqual([]);
    // Navigation can cancel pending resources without a transport failure.
    // Keep cancellations in the attachment, but never rely on a console event
    // to detect real network failures: it may arrive after fixture teardown.
    expect(failedRequests.filter(request => request.failure !== 'net::ERR_ABORTED'),
      'No failed network requests').toEqual([]);
  }, { auto: true }],
});
module.exports = { test, expect };
