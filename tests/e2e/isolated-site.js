const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

function createSite(source) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-e2e-'));
  try {
    const files = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], { cwd: source, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).split('\0');
    for (const file of new Set(files)) {
      if (!file || /^(?:\.git|\.claude|\.agents|\.codex|node_modules|vendor|storage|overrides|releases|test-results|playwright-report)(?:\/|$)/.test(file)
          || file === 'config/config.php' || file === 'config/overrides.php' || file === 'installed.lock') continue;
      const from = path.resolve(source, file), to = path.resolve(root, file);
      if (!to.startsWith(root + path.sep) || !from.startsWith(path.resolve(source) + path.sep)) throw new Error('Invalid snapshot path');
      if (!fs.existsSync(from) || !fs.lstatSync(from).isFile()) continue;
      fs.mkdirSync(path.dirname(to), { recursive: true });
      fs.copyFileSync(from, to);
    }
    // Dependencies are shared read-only; config, fixtures and runtime files are private copies.
    for (const dependency of ['node_modules', 'vendor']) {
      const target = path.join(source, dependency);
      if (fs.existsSync(target)) fs.symlinkSync(target, path.join(root, dependency), process.platform === 'win32' ? 'junction' : 'dir');
    }
    for (const directory of [
      'storage',
      'storage/cache',
      'storage/logs',
      'storage/login_throttle',
      'uploads',
      'uploads/images',
      'uploads/files',
      'uploads/videos',
      'uploads/albums',
    ]) {
      fs.mkdirSync(path.join(root, directory), { recursive: true });
    }
    return root;
  } catch (error) {
    removeSite(root);
    throw error;
  }
}

function removeSite(root) {
  const resolved = path.resolve(root);
  if (path.dirname(resolved) !== path.resolve(os.tmpdir()) || !path.basename(resolved).startsWith('yikai-e2e-')) throw new Error('Refusing unsafe cleanup');
  fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3 });
}

module.exports = { createSite, removeSite };
