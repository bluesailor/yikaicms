# 易开网页构建器 Pro（yikai-builder）

状态：本地开发候选，不是已发布的付费功能包。

## 组成

- `access.php` / `main.php`：授权桥（`blox_pro_feature_allowed`），只在 `licensed` 档被核心调用。
- `editor.php`：作者端模块注册。只挂编辑器钩子 `blox_editor_scripts`、`blox_editor_panel`，不参与保存校验与前台渲染。
- 已迁入的作者端模块（`BLOX_PRO_EDITOR_MODULES`），交互方法在 `assets/blox-pro-editor.js`，以 `window.BloxProEditor.methods` 混入编辑器：
  - `display_conditions`：元素/区块显示条件编辑面板（`editor/conditions-panel.php`）及条件增删改方法。
  - `style_presets`：元素全局样式选择器（`editor/style-preset-picker.php`）及 `globalStyleOptions/globalStyleLabel/applyGlobalStyle`。
  - `query_loop`：循环模板面板（`editor/loop-template-card.php`）；专业控件与循环子元素仅在本模块加载时下发给编辑器。
  - `pricing`：价格方案元素的套餐编辑（`editor/pricing-plans.php`、`assets/blox-pro-pricing.js`）；未放行时元素面板不提供价格方案，已有价格方案整体冻结，前台照常渲染（含按月/按年切换）。
  - `table`：表格元素的创建、网格编辑与画布单元格编辑（`editor/table-grid.php`、`assets/blox-pro-table.js`）；未放行时元素面板不提供表格，已有表格整张冻结，前台照常渲染。
- 与核心基础输入交织的共用逻辑（标题绑定弹层、循环宿主判断、设计系统对话框）留在核心，模块缺失时按同一开关收起。

核心保留：能力策略、服务端保护字段比较与保存校验、条件匹配与前台渲染、样式快照输出。
停用或删除插件后，旧文档照常渲染，受保护字段照常保留；编辑器只是不再显示对应面板，并提示前往插件管理。

## 分发（当前：免费期）

- `config/blox-feature-policy.php` 各项均为 `free` 期间，本插件列入 `config/blox-assets.json` 的 `core`，随完整包分发并默认启用：
  新装由 `install/sql/*.sql` 登记为启用；已装站点由迁移 `20260914_enable_blox_pro_editor_modules` 补登记（不覆盖管理员已停用状态）。
- 任一能力切换为 `licensed` 之前，必须把本插件移回 `pro` 清单并走服务端受控下载；`BloxProAccessTest` 以策略文件为准校验这一对应关系。

## 使用条件（licensed 档）

- CMS v1.20.0+、PHP 8.0+。与 CMS 核心的最低 PHP 版本一致。
- 通过插件管理正常安装并启用 `yikai-builder`。
- 站点已有专业授权已激活，授权包含 `blox` 模块；只注册账号不能开启。
- 使用现有 CMS 签名授权缓存与联网校验，不新建授权表、不储存第二份密钥。
- 服务期到期不主动收回已有模块；停用授权、域名不符等状态按核心授权服务处理。网络中断沿用核心缓存宽限期，而不是永久放行。

## 编辑边界

核心 `config/blox-feature-policy.php` 决定每项为 `free`、`licensed` 或 `disabled`。
`licensed` 必须同时满足：插件已加载且仍启用、CMS 版本符合、拥有模块、功能位于插件白名单。
`free` 不调用授权服务。未知功能默认拒绝。

基础编辑、默认产品/文章详情页编辑不收费。模板和全站主题下载由市场服务单独判断，不能用本地插件布尔值代替下载授权。

**本候选尚未把现有功能改为收费。** 旧文档保护（单页/栏目/首页/模板/预览/单页历史恢复）已接入；切换策略前仍需完成剩余作者端迁移与 v1.20.0 安装/启停验收。

## 包与上架

- 当前包含授权桥与第一个实质作者端模块，不是源码加密方案。
- 付费 ZIP 必须走服务端受控下载，不得上传到公开静态 ZIP 地址；校验专业授权、绑定域名、CMS 最低版本和包版本，并保留 SHA256/RSA 校验。
- 本地开发不自动改站点授权、不提升 CMS 版本，不自动发布到市场。
