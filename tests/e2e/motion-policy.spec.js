const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, observeConsole, frame } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('site motion policy controls real published effects without disabling interactions @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'serialized settings and publication');
  const errors = observeConsole(page);
  await page.goto('/admin/theme.php?tab=settings');
  const settings = await page.getByTestId('theme-settings-panel').locator('form').evaluate(el => new URLSearchParams(new FormData(el)).toString());
  const saveMode = async level => {
    const form = new URLSearchParams(settings); form.set('motion_intensity', level);
    await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(form) });
  };
  const front = await page.context().newPage();
  try {
    await openPageEditor(page, fixtures.blox_page);
    await page.evaluate(() => {
      const a = window.Alpine.$data(document.body);
      a.sections = [{ id: 'motion-section', type: 'section', settings: { padding: 'md' }, columns: [{ id: 'motion-column', settings: {}, elements: [
        { id: 'motion-heading', type: 'heading', data: { text: 'Motion acceptance', animation: 'fade-up', animation_trigger: 'load' } },
        { id: 'motion-button', type: 'button', data: { text: 'Functional button', url: '#motion-target', hover_effect: 'lift', animation: 'fade' } },
        { id: 'motion-group', type: 'container', data: { animation_stagger: true, children: [
          { id: 'motion-first', type: 'heading', data: { text: 'First' } },
          { id: 'motion-second', type: 'heading', data: { text: 'Second', html_id: 'motion-target' } }
        ] } }
      ] }] }];
    });
    await page.getByTestId('blox-save').click();
    await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|clean|published/);
    page.once('dialog', d => d.accept());
    await page.getByTestId('blox-publish-page').click();
    await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/);

    // The editor keeps group children visible and supports an explicit group replay.
    const canvas = await frame(page);
    await expect(canvas.getByText('First', { exact: true })).toBeVisible();
    await canvas.locator('[data-stagger]').evaluate(node => window.YikaiMotion.replayGroup(node));
    expect(await canvas.locator('[data-stagger]').evaluate(node => Array.from(node.children).filter(child => !child.matches('.yk-gap-resizer, .yk-column-resizer, .yk-empty-hint')).map(child => child.getAnimations().length))).toEqual([2, 2]);
    expect(await canvas.locator('.yk-gap-resizer, .yk-column-resizer').evaluateAll(nodes => nodes.every(node => node.getAnimations().length === 0))).toBe(true);

    for (const mode of ['none', 'light', 'standard']) {
      await saveMode(mode);
      await front.goto(fixtures.blox_page_url);
      await expect(front.locator('meta[name="yk-motion"]')).toHaveAttribute('content', mode);
      await expect.poll(() => front.evaluate(() => !!window.YikaiMotion)).toBe(true);
      const state = await front.locator('[data-animate]').first().evaluate(node => {
        window.YikaiMotion.replay(node, 'fade-up');
        return { level: window.YikaiMotion.level(), animations: node.getAnimations().map(a => ({ duration: a.effect.getTiming().duration, composite: a.effect.composite })) };
      });
      expect(state.level).toBe(mode);
      expect(state.animations.length).toBe(mode === 'none' ? 0 : mode === 'light' ? 1 : 2);
      if (mode === 'light') expect(state.animations[0].duration).toBe(180);
      if (mode === 'standard') {
        expect(state.animations.some(a => a.composite === 'add')).toBe(true);
        const animated = front.locator('[data-animate]').first();
        await animated.evaluate(node => {
          node.classList.add('motion-hover-contract');
          const style = document.createElement('style');
          style.textContent = '.motion-hover-contract:hover{transform:scale(1.05)}';
          document.head.appendChild(style);
        });
        await animated.hover();
        const scale = await animated.evaluate(node => {
          window.YikaiMotion.replay(node, 'fade-up');
          return new DOMMatrix(getComputedStyle(node).transform).a;
        });
        expect(scale).toBeCloseTo(1.05, 2);
      }
      const button = front.locator('[data-yk-motion-hover]');
      await button.hover();
      if (mode === 'none') expect(await button.evaluate(el => getComputedStyle(el).translate)).toBe('none');
      await button.click();
      await expect(front).toHaveURL(/#motion-target$/);
    }
    // An active visitor preference cancels already-running effects, not only the initial scan.
    await front.locator('[data-animate]').first().evaluate(node => window.YikaiMotion.replay(node, 'fade-up'));
    await front.emulateMedia({ reducedMotion: 'reduce' });
    await expect.poll(() => front.locator('[data-animate]').first().evaluate(node => node.getAnimations().length)).toBe(0);
    await front.emulateMedia({ reducedMotion: 'no-preference' });
    // Touch-width exclusion changes animation only, never visibility or clickability.
    await front.setViewportSize({ width: 390, height: 844 });
    expect(await front.locator('[data-animate]').first().evaluate(node => {
      node.setAttribute('data-animate-device', 'desktop');
      window.YikaiMotion.replay(node, 'fade-up');
      return node.getAnimations().length;
    })).toBe(0);

    // The published content remains readable with the entrance script completely unavailable.
    await front.route('**/assets/js/scroll-anim.js*', route => route.abort());
    await front.reload();
    await expect(front.getByText('Motion acceptance', { exact: true })).toBeVisible();
    await expect(front.getByText('First', { exact: true })).toBeVisible();
    expect(await front.locator('[data-animate]').first().evaluate(node => getComputedStyle(node).opacity)).toBe('1');
    await front.unroute('**/assets/js/scroll-anim.js*');

    // Backend rejects a forged level atomically.
    await saveMode('invalid');
    await page.goto('/admin/theme.php?tab=settings');
    await expect(page.getByTestId('motion-intensity')).toHaveValue('standard');
    expect(errors.filter(e => /pageerror|Alpine Expression Error/.test(e))).toEqual([]);
  } finally {
    await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(new URLSearchParams(settings)) });
    await front.close();
  }
});
