const { test, expect } = require('@playwright/test');
const { openEditor } = require('./helpers');

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single desktop security baseline');
  await openEditor(page);
});

test('preview requires CSRF and blocks submitted code scripts @ci', async ({ page }) => {
  const document = [{
    id: 'security-section',
    settings: {},
    columns: [{
      id: 'security-column',
      elements: [{
        id: 'security-code',
        type: 'code',
        data: {
          html: '<div data-security-marker="1">safe</div><script>parent.__bloxPreviewCompromised = true;<\/script>',
        },
      }],
    }],
  }];

  const rejected = await page.request.post('/admin/blox_preview.php?home=1', {
    form: { action: 'preview', blox: '1', blocks_data: JSON.stringify(document) },
  });
  // CMS JSON helpers keep HTTP 200 and expose the application status in `code`.
  expect((await rejected.json()).code).toBe(403);

  const token = await page.evaluate(() => window.Alpine.$data(document.body).csrf);
  const accepted = await page.request.post('/admin/blox_preview.php?home=1', {
    form: {
      action: 'preview',
      blox: '1',
      blocks_data: JSON.stringify(document),
      _token: token,
    },
  });
  expect(accepted.status()).toBe(200);
  const html = await accepted.text();
  expect(html).toContain('http-equiv="Content-Security-Policy"');
  expect(html).toContain('data-security-marker="1"');

  const compromised = await page.evaluate((srcdoc) => new Promise((resolve) => {
    window.__bloxPreviewCompromised = false;
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.addEventListener('load', () => {
      window.setTimeout(() => {
        const result = window.__bloxPreviewCompromised;
        iframe.remove();
        resolve(result);
      }, 80);
    }, { once: true });
    iframe.srcdoc = srcdoc;
    document.body.appendChild(iframe);
  }), html);
  expect(compromised).toBe(false);
});
