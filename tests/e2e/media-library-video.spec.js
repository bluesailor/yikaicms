const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

function updateMediaAuditFixture(action, key, value = '', url = '') {
  const script = [
    'define("ROOT_PATH", getcwd());',
    'require ROOT_PATH . "/includes/init.php";',
    'if ($argv[1] === "add") {',
    '  db()->insert("settings", ["group" => "blox", "key" => $argv[2], "value" => base64_decode($argv[3], true), "type" => "textarea", "name" => "", "tip" => "", "options" => null, "sort_order" => 0]);',
    '} else {',
    '  db()->delete("settings", "`key` = ?", [$argv[2]]);',
    '  if ($argv[4] !== "") db()->delete("media", "url = ?", [$argv[4]]);',
    '}',
  ].join(' ');
  execFileSync('php', ['-r', script, action, key, Buffer.from(value).toString('base64'), url], {
    cwd: process.cwd(),
    stdio: 'pipe',
  });
}

async function scanMedia(page) {
  await page.goto('/admin/media.php', { waitUntil: 'domcontentloaded' });
  const result = await page.evaluate(async () => {
    const response = await fetch('/admin/media_api.php?action=scan', { method: 'POST' });
    return response.json();
  });
  expect(result.code).toBe(0);
  await page.waitForLoadState('networkidle');
}

test('standalone media library filters, sorts, and paginates video cards @ci', async ({ page }, testInfo) => {
  const keyword = `media-page-${process.pid}-${testInfo.project.name}`.toLowerCase();
  const videoDir = path.join(process.cwd(), 'uploads', 'videos');
  fs.mkdirSync(videoDir, { recursive: true });
  for (let index = 1; index <= 25; index += 1) {
    fs.writeFileSync(path.join(videoDir, `${keyword}-${String(index).padStart(2, '0')}.mp4`), Buffer.alloc(index));
  }

  try {
    await scanMedia(page);
    const errors = observeConsole(page);
    await page.goto(`/admin/media.php?type=video&keyword=${encodeURIComponent(keyword)}&sort=largest`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByTestId('media-sort')).toHaveValue('largest');
    await expect(page.getByTestId('media-type-tabs').locator('[data-media-type="video"]')).toHaveAttribute('aria-current', 'page');
    const cards = page.locator('#mediaGrid [data-media-card]');
    await expect(cards).toHaveCount(24);
    await expect(cards.first()).toContainText(`${keyword}-25.mp4`);
    await expect(cards.first().locator('[data-media-created-date]')).toContainText(/\d{4}-\d{2}-\d{2}/);

    const preview = cards.first().locator('[data-media-video-preview]');
    await expect(preview).toHaveAttribute('data-src', new RegExp(`${keyword}-25\\.mp4$`));
    await expect(cards.first()).toHaveAttribute('data-video-status', 'error', { timeout: 12_000 });

    const next = page.locator('a[href*="page=2"]');
    await expect(next).toHaveAttribute('href', /type=video/);
    await expect(next).toHaveAttribute('href', /sort=largest/);
    await expect(next).toHaveAttribute('href', new RegExp(`keyword=${encodeURIComponent(keyword)}`));
    await next.click();
    await expect(page.locator('#mediaGrid [data-media-card]')).toHaveCount(1);
    await expect(page.locator('#mediaGrid [data-media-card]').first()).toContainText(`${keyword}-01.mp4`);

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('standalone-video-library.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    for (const name of fs.readdirSync(videoDir)) {
      if (name.startsWith(keyword)) fs.rmSync(path.join(videoDir, name), { force: true });
    }
  }
});

test('standalone upload endpoint persists videos in the video library @ci', async ({ page }) => {
  const filename = `standalone-upload-${process.pid}-${Date.now()}.webm`;
  let uploadedUrl = '';

  try {
    await page.goto('/admin/media.php', { waitUntil: 'domcontentloaded' });
    const payload = await page.evaluate(async (name) => {
      const bytes = new Uint8Array([0x1a, 0x45, 0xdf, 0xa3, 0x42, 0x86, 0x81, 0x01]);
      const body = new FormData();
      body.append('file', new File([bytes], name, { type: 'video/webm' }));
      body.append('type', 'videos');
      const response = await fetch('/admin/upload.php', { method: 'POST', body });
      return response.json();
    }, filename);

    expect(payload.code).toBe(0);
    expect(payload.data.type).toBe('video');
    expect(payload.data.url).toMatch(/^\/uploads\/videos\//);
    uploadedUrl = payload.data.url;

    await page.goto(`/admin/media.php?type=video&keyword=${encodeURIComponent(filename)}`, { waitUntil: 'domcontentloaded' });
    const card = page.locator('#mediaGrid [data-media-card]');
    await expect(card).toHaveCount(1);
    await expect(card.locator('[data-media-video-preview]')).toHaveAttribute('data-src', uploadedUrl);
  } finally {
    if (uploadedUrl) {
      updateMediaAuditFixture('delete', `cleanup-${filename}`, '', uploadedUrl);
      fs.rmSync(path.join(process.cwd(), uploadedUrl.replace(/^\/+/, '')), { force: true });
    }
  }
});

test('standalone media library extracts first frames from two real videos @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Real video decoding is sampled once on desktop.');
  const samples = ['blox-test-flower.mp4', 'blox-test-friday.mp4'];
  const videoDir = path.join(process.cwd(), 'uploads', 'videos');
  test.skip(samples.some(name => !fs.existsSync(path.join(videoDir, name))), 'Local video samples are not available.');

  await scanMedia(page);
  const errors = observeConsole(page);
  await page.goto('/admin/media.php?type=video&keyword=blox-test-&sort=name', { waitUntil: 'domcontentloaded' });

  const cards = page.locator('#mediaGrid [data-media-card]');
  await expect(cards).toHaveCount(2);
  await expect(cards.locator('[data-media-video-preview]')).toHaveCount(2);
  await expect(cards.first()).toHaveAttribute('data-video-status', 'ready', { timeout: 12_000 });
  await expect(cards.nth(1)).toHaveAttribute('data-video-status', 'ready', { timeout: 12_000 });
  await expect(cards.first().locator('[data-media-video-meta]')).toHaveText(/^\d+x\d+ · \d+:\d{2}$/);
  await expect(cards.nth(1).locator('[data-media-video-meta]')).toHaveText(/^\d+x\d+ · \d+:\d{2}$/);
  await cards.first().locator('[data-media-preview-button]').click();
  await expect(page.locator('#previewModal')).toBeVisible();
  await page.locator('#previewFrame video').click({ position: { x: 8, y: 8 } });
  await expect(page.locator('#previewModal')).toBeVisible();
  await page.evaluate(() => window.closePreview());

  const uploadResponse = page.waitForResponse(response => (
    response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/media_api.php'
      && new URL(response.url()).searchParams.get('action') === 'upload'
  ));
  await page.locator('#fileInput').setInputFiles(path.join(videoDir, samples[0]));
  const uploadPayload = await (await uploadResponse).json();
  expect(uploadPayload.code).toBe(0);
  expect(uploadPayload.data.url).toMatch(/^\/uploads\/videos\//);
  await expect(cards).toHaveCount(3, { timeout: 8_000 });

  await page.screenshot({ path: testInfo.outputPath('standalone-real-video-first-frames.png'), fullPage: true });
  expect(errors).toEqual([]);
});

test('media deletion is blocked while a banner references the video @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Destructive reference guard is sampled once.');
  const token = `media-usage-${process.pid}-${Date.now()}`;
  const name = `${token}-used.mp4`;
  const unusedName = `${token}-unused.mp4`;
  const videoPath = path.join(process.cwd(), 'uploads', 'videos', name);
  const unusedPath = path.join(process.cwd(), 'uploads', 'videos', unusedName);
  const videoUrl = `/uploads/videos/${name}`;
  fs.mkdirSync(path.dirname(videoPath), { recursive: true });
  fs.writeFileSync(videoPath, Buffer.alloc(64));
  fs.writeFileSync(unusedPath, Buffer.alloc(32));
  let bannerId = 0;

  try {
    await scanMedia(page);
    await page.goto(`/admin/media.php?type=video&keyword=${encodeURIComponent(token)}`, { waitUntil: 'domcontentloaded' });
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(csrf).toBeTruthy();
    const card = page.locator('[data-media-card]').filter({ hasText: name });
    const unusedCard = page.locator('[data-media-card]').filter({ hasText: unusedName });
    await expect(card).toHaveCount(1);
    await expect(unusedCard).toHaveCount(1);
    const mediaId = Number(await card.getAttribute('data-id'));
    const unusedMediaId = Number(await unusedCard.getAttribute('data-id'));
    expect(mediaId).toBeGreaterThan(0);
    expect(unusedMediaId).toBeGreaterThan(0);

    const created = await page.request.post('/admin/banner.php', { form: {
      _token: csrf,
      action: 'save',
      id: '0',
      title: 'Media usage guard',
      subtitle: '',
      image: '',
      image_mobile: '',
      media_type: 'video',
      video: videoUrl,
      video_mobile_mode: 'poster',
      btn1_text: '',
      btn1_url: '',
      btn2_text: '',
      btn2_url: '',
      link_url: '',
      link_target: '_self',
      position: 'media-usage-test',
      start_time: '',
      end_time: '',
      content_motion: 'inherit',
      background_motion: 'inherit',
      sort_order: '0',
      status: '1',
    } });
    const createdPayload = await created.json();
    expect(createdPayload.code).toBe(0);
    bannerId = Number(createdPayload.data.id);

    const usage = await page.evaluate(async (id) => {
      const body = new FormData();
      body.append('action', 'usage');
      body.append('id', String(id));
      return (await fetch('', { method: 'POST', body })).json();
    }, mediaId);
    expect(usage.code).toBe(0);
    expect(usage.data).toMatchObject({ blocked: true, files: 1, count: 1 });
    expect(usage.data.references[0]).toMatchObject({
      media_id: mediaId,
      source_type: 'banner',
      source_id: bannerId,
      kind: 'banner_video',
      edit_url: '/admin/banner.php',
    });
    let confirmDialogs = 0;
    page.on('dialog', async (dialog) => {
      confirmDialogs += 1;
      await dialog.dismiss();
    });
    const usageResponse = page.waitForResponse((response) => {
      return new URL(response.url()).pathname === '/admin/media.php'
        && response.request().method() === 'POST';
    });
    await card.locator('button[onclick^="deleteMedia"]').click({ force: true });
    expect((await (await usageResponse).json()).data.blocked).toBe(true);
    await expect(page.locator('body > .bg-red-500')).toContainText(/正在被|used in|使用中/);
    expect(confirmDialogs).toBe(0);

    const blocked = await page.evaluate(async (id) => {
      const body = new FormData();
      body.append('action', 'delete');
      body.append('id', String(id));
      return (await fetch('', { method: 'POST', body })).json();
    }, mediaId);
    expect(blocked.code).not.toBe(0);
    expect(blocked.msg).toMatch(/正在被|used in|使用中/);
    expect(fs.existsSync(videoPath)).toBe(true);
    await expect(card).toHaveCount(1);

    const batchBlocked = await page.evaluate(async (ids) => {
      const body = new FormData();
      body.append('action', 'batch_delete');
      ids.forEach((id) => body.append('ids[]', String(id)));
      return (await fetch('', { method: 'POST', body })).json();
    }, [mediaId, unusedMediaId]);
    expect(batchBlocked.code).not.toBe(0);
    expect(fs.existsSync(videoPath)).toBe(true);
    expect(fs.existsSync(unusedPath)).toBe(true);
    await expect(card).toHaveCount(1);
    await expect(unusedCard).toHaveCount(1);

    const removedBanner = await page.request.post('/admin/banner.php', {
      form: { _token: csrf, action: 'delete', id: String(bannerId) },
    });
    expect((await removedBanner.json()).code).toBe(0);
    bannerId = 0;

    const deleted = await page.evaluate(async (ids) => {
      const body = new FormData();
      body.append('action', 'batch_delete');
      ids.forEach((id) => body.append('ids[]', String(id)));
      return (await fetch('', { method: 'POST', body })).json();
    }, [mediaId, unusedMediaId]);
    expect(deleted.code).toBe(0);
    expect(fs.existsSync(videoPath)).toBe(false);
    expect(fs.existsSync(unusedPath)).toBe(false);
  } finally {
    if (bannerId > 0) {
      await page.goto('/admin/banner.php', { waitUntil: 'domcontentloaded' });
      const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      await page.request.post('/admin/banner.php', {
        form: { _token: csrf || '', action: 'delete', id: String(bannerId) },
      });
    }
    fs.rmSync(videoPath, { force: true });
    fs.rmSync(unusedPath, { force: true });
  }
});

test('media deletion is blocked by slash-escaped Blox image references @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Destructive Blox reference guard is sampled once.');
  const token = `media-blox-usage-${process.pid}-${Date.now()}`.toLowerCase();
  const name = `${token}.png`;
  const imagePath = path.join(process.cwd(), 'uploads', 'images', name);
  const imageUrl = `/uploads/images/${name}`;
  const settingKey = `home_blox_data_${token}`;
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  fs.mkdirSync(path.dirname(imagePath), { recursive: true });
  fs.writeFileSync(imagePath, png);

  try {
    await scanMedia(page);
    await page.goto(`/admin/media.php?type=image&keyword=${encodeURIComponent(name)}`, { waitUntil: 'domcontentloaded' });
    const card = page.locator('[data-media-card]').filter({ hasText: name });
    await expect(card).toHaveCount(1);
    const mediaId = Number(await card.getAttribute('data-id'));
    expect(mediaId).toBeGreaterThan(0);

    const document = JSON.stringify([{
      id: 'audit-section',
      settings: { bg_image: imageUrl },
      columns: [{
        id: 'audit-column',
        elements: [{ type: 'image', data: { src: imageUrl, link_url: imageUrl } }],
      }],
    }]).replaceAll('/', '\\/');
    updateMediaAuditFixture('add', settingKey, document);

    const usage = await page.evaluate(async (id) => {
      const body = new FormData();
      body.append('action', 'usage');
      body.append('id', String(id));
      return (await fetch('', { method: 'POST', body })).json();
    }, mediaId);
    expect(usage.code).toBe(0);
    expect(usage.data).toMatchObject({ blocked: true, files: 1, count: 2 });
    expect(usage.data.references.map(reference => reference.kind).sort()).toEqual([
      'background_image',
      'image_element',
    ]);

    const blocked = await page.evaluate(async (id) => {
      const body = new FormData();
      body.append('action', 'delete');
      body.append('id', String(id));
      return (await fetch('', { method: 'POST', body })).json();
    }, mediaId);
    expect(blocked.code).not.toBe(0);
    expect(fs.existsSync(imagePath)).toBe(true);
    await expect(card).toHaveCount(1);
  } finally {
    updateMediaAuditFixture('remove', settingKey, '', imageUrl);
    fs.rmSync(imagePath, { force: true });
  }
});
