const fs = require('fs');
const os = require('os');
const path = require('path');

module.exports = function installMarketThemes(root, slugs) {
    if (!process.env.CI && (path.dirname(root) !== path.resolve(os.tmpdir())
        || !path.basename(root).startsWith('yikai-e2e-'))) {
        throw new Error('Run market tests through run-local.js in a disposable site');
    }
    const created = [];
    function verify(source, target) {
        for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
            const from = path.join(source, entry.name), to = path.join(target, entry.name);
            if (entry.isDirectory()) verify(from, to);
            else if (!fs.existsSync(to) || !fs.readFileSync(from).equals(fs.readFileSync(to))) {
                throw new Error(`Installed theme differs from market source: ${to}`);
            }
        }
    }
    for (const slug of slugs) {
        if (!/^[a-z0-9-]+$/.test(slug)) throw new Error('Invalid fixture theme');
        const source = path.join(root, 'marketplace/themes', slug);
        const target = path.join(root, 'themes', slug);
        if (fs.existsSync(target)) verify(source, target);
        else {
            fs.cpSync(source, target, { recursive: true });
            created.push(target);
        }
    }
    return () => {
        for (const target of created) {
            if (path.dirname(target) !== path.join(root, 'themes') || !slugs.includes(path.basename(target))) {
                throw new Error('Unsafe fixture cleanup');
            }
            fs.rmSync(target, { recursive: true, force: true });
        }
    };
};
