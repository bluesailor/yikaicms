(() => {
  const path = window.location.pathname;
  if (!/^\/yikai-[a-z0-9-]+(?:\/|$)/.test(path) ||
      /^\/yikai-[a-z0-9-]+\/(?:admin|api|member|install|bin)(?:\/|$)/.test(path)) {
    return;
  }

  const language = document.documentElement.lang.toLowerCase();
  const copy = language.startsWith('ja')
    ? { nav: 'デモツール', catalog: 'テンプレート', share: 'URLをコピー', copied: 'コピーしました', top: 'ページ上部へ', open: 'デモツールを開く', close: 'デモツールを閉じる' }
    : language.startsWith('en')
      ? { nav: 'Demo tools', catalog: 'Templates', share: 'Copy link', copied: 'Copied', top: 'Back to top', open: 'Open demo tools', close: 'Close demo tools' }
      : { nav: '演示工具', catalog: '模板库', share: '复制链接', copied: '已复制', top: '回到顶部', open: '打开演示工具', close: '关闭演示工具' };

  const icons = {
    catalog: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    share: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    top: '<path d="m5 15 7-7 7 7"/><path d="M12 8v12"/>',
    menu: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'
  };
  const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${icons[name]}</svg>`;

  const host = document.createElement('div');
  host.id = 'yikai-demo-rail';
  const shadow = host.attachShadow({ mode: 'open' });
  shadow.innerHTML = `
    <style>
      :host { all: initial; position: fixed; z-index: 2147482000; top: 50%; right: 18px; transform: translateY(-50%); font: 12px/1.35 system-ui, -apple-system, "Microsoft YaHei", sans-serif; color: #152434; }
      * { box-sizing: border-box; }
      .rail { display: grid; gap: 1px; overflow: hidden; border: 1px solid rgba(18, 39, 61, .12); border-radius: 13px; background: #e8edf0; box-shadow: 0 12px 35px rgba(8, 23, 39, .18); }
      .item { width: 70px; min-height: 70px; padding: 11px 5px 9px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; border: 0; background: #fff; color: #21374d; text-align: center; text-decoration: none; cursor: pointer; font: inherit; white-space: nowrap; }
      .item:hover, .item:focus-visible { background: #e9f3f5; color: #126a75; }
      .item:focus-visible, .toggle:focus-visible { outline: 3px solid #188aa0; outline-offset: -3px; }
      .item svg, .toggle svg { width: 23px; height: 23px; flex: none; }
      .toggle { display: none; width: 50px; height: 50px; place-items: center; padding: 0; border: 0; border-radius: 50%; background: #142b43; color: #fff; box-shadow: 0 8px 24px rgba(8, 23, 39, .24); cursor: pointer; }
      .status { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
      @media (max-width: 767px) {
        :host { top: auto; right: 14px; bottom: 18px; transform: none; }
        .toggle { display: grid; margin-left: auto; }
        .rail { position: absolute; right: 0; bottom: 60px; display: none; }
        :host([data-open]) .rail { display: grid; }
      }
      @media print { :host { display: none; } }
    </style>
    <nav class="rail" aria-label="${copy.nav}">
      <a class="item" href="/" title="${copy.catalog}">${icon('catalog')}<span>${copy.catalog}</span></a>
      <button class="item" type="button" data-action="copy" title="${copy.share}">${icon('share')}<span>${copy.share}</span></button>
      <button class="item" type="button" data-action="top" title="${copy.top}">${icon('top')}<span>${copy.top}</span></button>
    </nav>
    <button class="toggle" type="button" aria-expanded="false" aria-label="${copy.open}">${icon('menu')}</button>
    <span class="status" role="status" aria-live="polite"></span>`;

  const toggle = shadow.querySelector('.toggle');
  const status = shadow.querySelector('.status');
  toggle.addEventListener('click', () => {
    const open = host.toggleAttribute('data-open');
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? copy.close : copy.open);
  });
  shadow.querySelector('[data-action="top"]').addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    host.removeAttribute('data-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', copy.open);
  });
  shadow.querySelector('[data-action="copy"]').addEventListener('click', async () => {
    let copied = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(window.location.href);
        copied = true;
      } else {
        const field = document.createElement('textarea');
        field.value = window.location.href;
        field.style.cssText = 'position:fixed;left:-9999px;top:0';
        document.body.appendChild(field);
        field.select();
        copied = document.execCommand('copy');
        field.remove();
      }
    } catch (_) { /* Clipboard access may be unavailable in some browsers. */ }
    status.textContent = copied ? copy.copied : window.location.href;
    if (copied) {
      const label = shadow.querySelector('[data-action="copy"] span');
      label.textContent = copy.copied;
      window.setTimeout(() => { label.textContent = copy.share; }, 1800);
    }
  });
  document.body.appendChild(host);
})();
