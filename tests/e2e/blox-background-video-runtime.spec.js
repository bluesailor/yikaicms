const { test, expect } = require('@playwright/test');

const VIDEO_URL = '/uploads/e2e-background-runtime.mp4';

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'runtime policy baseline');
});

async function openRuntimePage(page, mobileMode = 'video', offset = 0) {
  await page.route('**/__blox-background-video-runtime', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><body style="margin:0">
      <div style="height:${offset}px"></div>
      <video style="display:block;width:640px;height:360px" muted loop playsinline preload="none" data-blox-background-video
        data-blox-mobile-video="${mobileMode}" data-blox-video-src="${VIDEO_URL}"></video>
      <script src="/assets/js/blox-background-video.js"></script>
    </body></html>`,
  }));
  await page.goto('/__blox-background-video-runtime');
}

function observeVideoRequests(page) {
  const requests = [];
  page.on('request', request => {
    if (new URL(request.url()).pathname === VIDEO_URL) requests.push(request.url());
  });
  return requests;
}

test('mobile poster policy never requests the background video @ci', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests = observeVideoRequests(page);
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page, 'poster');

  const video = page.locator('[data-blox-background-video]');
  await expect(video).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);
});

test('reduced motion never requests the background video @ci', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const requests = observeVideoRequests(page);
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page);

  await expect(page.locator('[data-blox-background-video]')).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);
});

test('save-data never requests the background video @ci', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'connection', {
      configurable: true,
      value: { saveData: true, addEventListener() {} },
    });
  });
  const requests = observeVideoRequests(page);
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page);

  await expect(page.locator('[data-blox-background-video]')).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);
});

test('dynamic replacement releases the old video and starts the new node @ci', async ({ page }) => {
  await page.addInitScript(() => {
    window.__bloxMediaCalls = { play: 0, pause: 0, load: 0 };
    HTMLMediaElement.prototype.play = function () {
      window.__bloxMediaCalls.play += 1;
      return Promise.resolve();
    };
    HTMLMediaElement.prototype.pause = function () { window.__bloxMediaCalls.pause += 1; };
    HTMLMediaElement.prototype.load = function () { window.__bloxMediaCalls.load += 1; };
  });
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page);
  await expect(page.locator('[data-blox-background-video]')).toHaveAttribute('src', VIDEO_URL);
  await expect.poll(() => page.evaluate(() => window.__bloxMediaCalls.play)).toBeGreaterThanOrEqual(1);

  const result = await page.evaluate(() => {
    const oldVideo = document.querySelector('[data-blox-background-video]');
    const replacement = oldVideo.cloneNode(false);
    oldVideo.replaceWith(replacement);
    document.dispatchEvent(new CustomEvent('blox:content-updated'));
    return {
      oldSrc: oldVideo.getAttribute('src'),
      callsAfterReplacement: { ...window.__bloxMediaCalls },
    };
  });

  expect(result.oldSrc).toBeNull();
  expect(result.callsAfterReplacement.pause).toBeGreaterThan(0);
  expect(result.callsAfterReplacement.load).toBeGreaterThan(0);
  await expect(page.locator('[data-blox-background-video]')).toHaveAttribute('src', VIDEO_URL);
  await expect.poll(() => page.evaluate(() => window.__bloxMediaCalls.play)).toBeGreaterThanOrEqual(2);
});

test('an offscreen background video waits for the viewport and pauses after leaving it @ci', async ({ page }) => {
  const requests = observeVideoRequests(page);
  await page.addInitScript(() => {
    window.__bloxPauseCalls = 0;
    const nativePause = HTMLMediaElement.prototype.pause;
    HTMLMediaElement.prototype.pause = function () {
      window.__bloxPauseCalls += 1;
      return nativePause.call(this);
    };
  });
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page, 'video', 1800);

  const video = page.locator('[data-blox-background-video]');
  await expect(video).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);

  await video.scrollIntoViewIfNeeded();
  await expect(video).toHaveAttribute('src', VIDEO_URL);
  await expect.poll(() => requests.length).toBeGreaterThan(0);

  const pausesBeforeLeaving = await page.evaluate(() => window.__bloxPauseCalls);
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect.poll(() => page.evaluate(() => window.__bloxPauseCalls)).toBeGreaterThan(pausesBeforeLeaving);
  await expect(video).toHaveAttribute('src', VIDEO_URL);
});
