const path = require('path');
const { test } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');
const { defineThemeLayoutTests } = require('./blox-banner-layout-helpers');
let cleanup = () => {};
test.beforeAll(() => { cleanup = installMarketThemes(path.resolve(__dirname, '../..'), ['business', 'minimal']); });
test.afterAll(() => cleanup());

defineThemeLayoutTests(['business', 'minimal']);
