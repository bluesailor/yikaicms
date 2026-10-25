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

/**
 * 画布与后台同源（没有 sandbox），挡住代码执行靠的是预览 CSP。除了 <script>，常见绕过还有
 * 事件属性、javascript: 链接、嵌套 srcdoc 框架、meta refresh——有「代码」权限的账号写进
 * 区块的这些内容，在更高权限的管理员打开编辑器时都不能借后台身份执行（2.1.3 补的回归）。
 */
test('preview CSP blocks event handlers, javascript: links and nested frames @ci', async ({ page }) => {
  const payload = [
    '<div data-security-marker="2">safe</div>',
    '<img alt="" src="data:image/png;base64,AAAA" onerror="parent.__bloxPreviewCompromised = \'onerror\'">',
    '<svg onload="parent.__bloxPreviewCompromised = \'svg-onload\'"></svg>',
    '<a id="yk-js-link" href="javascript:parent.__bloxPreviewCompromised=\'javascript-url\'">link</a>',
    '<button id="yk-onclick" type="button" onclick="parent.__bloxPreviewCompromised = \'onclick\'">button</button>',
    '<iframe srcdoc="&lt;script&gt;parent.parent.__bloxPreviewCompromised = \'nested-srcdoc\'&lt;/script&gt;"></iframe>',
    '<meta http-equiv="refresh" content="0;url=javascript:parent.__bloxPreviewCompromised=\'meta-refresh\'">',
  ].join('');
  const document = [{
    id: 'security-section-2',
    settings: {},
    columns: [{ id: 'security-column-2', elements: [{ id: 'security-code-2', type: 'code', data: { html: payload } }] }],
  }];
  const token = await page.evaluate(() => window.Alpine.$data(document.body).csrf);
  const accepted = await page.request.post('/admin/blox_preview.php?home=1', {
    form: { action: 'preview', blox: '1', blocks_data: JSON.stringify(document), _token: token },
  });
  expect(accepted.status()).toBe(200);
  const html = await accepted.text();
  expect(html).toContain('data-security-marker="2"');

  const compromised = await page.evaluate((srcdoc) => new Promise((resolve) => {
    window.__bloxPreviewCompromised = false;
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;left:0;top:0;width:800px;height:600px;opacity:0';
    iframe.addEventListener('load', () => {
      const doc = iframe.contentDocument;
      for (const id of ['yk-js-link', 'yk-onclick']) {
        const el = doc && doc.getElementById(id);
        if (el) el.click();
      }
      window.setTimeout(() => {
        const result = window.__bloxPreviewCompromised;
        iframe.remove();
        resolve(result);
      }, 400);
    }, { once: true });
    iframe.srcdoc = srcdoc;
    document.body.appendChild(iframe);
  }), html);
  expect(compromised, 'no payload may run with the admin page as parent').toBe(false);
});
