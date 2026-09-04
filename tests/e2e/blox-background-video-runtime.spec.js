const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const VIDEO_URL = '/uploads/e2e-background-runtime.mp4';
const REAL_VIDEO_URL = '/uploads/videos/blox-test-flower.mp4';
const POSTER_URL = '/assets/images/demo/about-office.jpg';

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'runtime policy baseline');
});

async function openRuntimePage(page, mobileMode = 'video', offset = 0, videoUrl = VIDEO_URL, posterUrl = '') {
  const posterAttr = posterUrl ? ` poster="${posterUrl}"` : '';
  const surfaceStyle = posterUrl
    ? `background-image:url('${posterUrl}');background-size:cover;background-position:center;`
    : 'background-color:#111827;';
  await page.route('**/__blox-background-video-runtime', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><body style="margin:0">
      <link rel="stylesheet" href="/assets/css/tailwind.css">
      <div style="height:${offset}px"></div>
      <div data-testid="runtime-surface" class="blox-has-bg" style="width:640px;height:360px;${surfaceStyle}">
        <div class="blox-bg-media" aria-hidden="true"><video muted loop playsinline preload="none" data-blox-background-video
          data-blox-mobile-video="${mobileMode}" data-blox-video-src="${videoUrl}"${posterAttr}></video></div>
        <div class="blox-content"></div>
      </div>
      <script src="/assets/js/blox-video-policy.js"></script>
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

test('mobile poster policy never requests the background video @ci @shard-media', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests = observeVideoRequests(page);
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page, 'poster');

  const video = page.locator('[data-blox-background-video]');
  await expect(video).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);
});

test('reduced motion never requests the background video @ci @shard-media', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const requests = observeVideoRequests(page);
  await page.route(`**${VIDEO_URL}`, route => route.fulfill({ status: 200, contentType: 'video/mp4', body: Buffer.alloc(32) }));
  await openRuntimePage(page);

  await expect(page.locator('[data-blox-background-video]')).not.toHaveAttribute('src', /.+/);
  await page.waitForTimeout(300);
  expect(requests).toEqual([]);
});

test('save-data never requests the background video @ci @shard-media', async ({ page }) => {
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

test('dynamic replacement releases the old video and starts the new node @ci @shard-media', async ({ page }) => {
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

test('an offscreen background video waits for the viewport and pauses after leaving it @ci @shard-media', async ({ page }) => {
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

test('a real background video reveals over its persistent poster fallback @local', async ({ page }, testInfo) => {
  test.skip(
    !fs.existsSync(path.join(process.cwd(), 'uploads', 'videos', 'blox-test-flower.mp4')),
    'Download the documented local MDN sample before running this acceptance test.'
  );
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await openRuntimePage(page, 'video', 0, REAL_VIDEO_URL, POSTER_URL);

  const surface = page.getByTestId('runtime-surface');
  const video = page.locator('[data-blox-background-video]');
  await expect(surface).toHaveCSS('background-image', /about-office\.jpg/);
  await expect(video).toHaveAttribute('poster', POSTER_URL);
  await expect(video).not.toHaveAttribute('src', /.+/);
  await expect(video).toHaveCSS('opacity', '0');
  await page.screenshot({ path: testInfo.outputPath('background-video-poster-fallback.png') });

  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await expect(video).toHaveAttribute('src', REAL_VIDEO_URL);
  await expect.poll(() => video.evaluate(node => node.readyState)).toBeGreaterThanOrEqual(3);
  await expect.poll(() => video.evaluate(node => node.currentTime)).toBeGreaterThan(0.05);
  await expect(video).toHaveClass(/blox-bg-video-ready/);
  await expect(video).toHaveCSS('opacity', '1');
  await page.screenshot({ path: testInfo.outputPath('background-video-playing-over-poster.png') });
  expect(errors).toEqual([]);
});
