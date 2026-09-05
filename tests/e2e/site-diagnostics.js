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
  diagnostics: [async ({ page, baseURL }, use, info) => {
    expect(new URL(baseURL).hostname).toBe('127.0.0.1');
    expect(path.basename(root)).toMatch(/^yikai-e2e-/);
    expect(fs.existsSync(path.join(root, 'storage/.smoke-state-backup/manifest.json'))).toBeTruthy();
    const before = logs(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => {
      if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`);
    });
    await use();
    const after = logs();
    for (const name of Object.keys(before)) expect(after).toHaveProperty(name);
    const added = Object.entries(after).map(([name, value]) => {
      expect(value.startsWith(before[name] || ''), 'Error log must not be cleared during test').toBeTruthy();
      return value.slice((before[name] || '').length);
    }).join('');
    await info.attach('new-php-errors', { body: added || '(none)', contentType: 'text/plain' });
    expect(added, 'No new CMS error log entries').toBe('');
    expect(errors, 'Browser and server errors').toEqual([]);
  }, { auto: true }],
});
module.exports = { test, expect };
