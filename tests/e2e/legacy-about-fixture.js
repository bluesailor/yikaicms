const { execFileSync } = require('child_process');
const path = require('path');

module.exports = function useLegacyAbout(test) {
  const fixture = (action) => execFileSync(process.env.PHP_BINARY || 'php',
    [path.join(__dirname, 'about-language-fixture.php'), action],
    { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
  // Legacy compatibility must not depend on the current new-home document format.
  test.beforeEach(() => { fixture('legacy'); });
  test.afterEach(() => { fixture('restore'); });
};
