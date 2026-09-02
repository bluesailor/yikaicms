# 第三方组件声明

YikaiCMS 随包分发以下第三方组件，各组件按其自身协议授权，版权归各自作者所有。
本声明不改变这些组件的授权状态；YikaiCMS 自身的授权见 `LICENSE`。

版本以随包文件头部的声明为准；下表标注的版本为本清单核对时的随包版本。

## 前端组件（assets/）

| 组件 | 版本 | 协议 | 项目地址 |
|---|---|---|---|
| TinyMCE | 6.8.5 | MIT | https://www.tiny.cloud |
| Alpine.js | — | MIT | https://alpinejs.dev |
| Tailwind CSS（编译产物） | — | MIT | https://tailwindcss.com |
| SortableJS | 1.15.6 | MIT | https://sortablejs.github.io/Sortable/ |
| Swiper | 11.2.10 | MIT | https://swiperjs.com |
| flatpickr | 4.6.13 | MIT | https://flatpickr.js.org |
| PhotoSwipe | 5.4.4 | MIT | https://photoswipe.com |
| Plyr | — | MIT | https://plyr.io |
| qrcode.js | — | MIT | https://github.com/davidshimjs/qrcodejs |
| Tabler Icons | 3.44.0 | MIT | https://tabler.io/icons |
| Bootstrap Icons | 1.13.1 | MIT | https://icons.getbootstrap.com |
| D3.js | 7.9.0 | ISC | https://d3js.org |
| d3-flextree | — | WTFPL | https://github.com/Klortho/d3-flextree |
| d3-org-chart | — | MIT | https://github.com/bumbeishvili/org-chart |

## 插件内置资源（经插件市场分发，不随核心安装包）

标志设计器（logo-maker）自 v1.18.6 起不再随核心安装包分发，改由后台插件市场按需安装。
其内置图标库仍按下列协议授权：

| 组件 | 版本 | 协议 | 项目地址 |
|---|---|---|---|
| Phosphor Icons（bold/duotone SVG 子集） | — | MIT | https://phosphoricons.com |
| Tabler Icons（outline SVG 子集） | — | MIT | https://tabler.io/icons |

许可原文随**插件包**分发，安装后见 `plugins/logo-maker/assets/icon-library/licenses/`。
SVG 本体自 v1.18.6 起打包为同目录下的 `icons.bin` + 索引，仅改变存放形式，不改变授权。

## PHP 依赖

核心发行包没有根目录 Composer 运行依赖，也不分发 `vendor/`。Composer、PHPUnit、
Psalm、PHP-Parser 等只用于开发与测试，见 `composer.json` 的 `require-dev`。

## 内置数据（includes/）

### 拼音词库（includes/pinyin/）

`includes/Pinyin.php` 的词库派生自 Rime 官方项目的简体拼音词典：

- Project: `rime/rime-pinyin-simp`
- Source: <https://github.com/rime/rime-pinyin-simp>
- Commit: `0c6861ef7420ee780270ca6d993d18d4101049d0`
- Original file: `pinyin_simp.dict.yaml`
- Original SHA256: `E341598343A0F0F2035BB1AAFC34A7F3BB7887DEEECB3F60796262AAA2983E6B`
- License: Apache License 2.0
- 许可副本：`includes/pinyin/LICENSE.txt`，作者名单：`includes/pinyin/AUTHORS.txt`

上游 README 载明该词典派生自 Android 开源项目的 PinyinIME（同为 Apache-2.0）。

**本项目所做的修改**（Apache-2.0 §4(b) 要求声明）：由 `tools/build_pinyin_dict.php`
按词频权重为每个汉字选定默认读音，去掉与逐字默认读音一致的冗余词条，并把单字表
改写为「音节 → 汉字」反向索引以压缩体积。产物为 `includes/pinyin/chars.php` 与
`includes/pinyin/phrases.php`；`includes/pinyin/overrides.php` 是本项目自行编写的
人工修正表，不属于上游数据。

> 选型说明：本词库替换了此前的 `overtrue/pinyin`。该包代码为 MIT，但其词库
> 载明派生自 **CC-CEDICT（CC BY-SA，copyleft）**，与下方「维护约定」第 2 条
> 冲突；改用 Apache-2.0 来源后不再有该问题。

## 维护约定

1. **升级任何组件的大版本前，先核对其协议是否变更。**
   例：TinyMCE 自 7.0 起改为 GPLv2+，本项目因此钉在 6.x，不得自动升级。
2. **不得引入与本软件许可协议冲突的组件。**
   具体而言：GPL / AGPL 及其他 copyleft 协议的代码**不得并入本软件本体**——
   本软件的许可协议包含「不得再分发」等限制条款，与 copyleft 的传染性要求直接冲突。
   如确需此类能力，应改为可选插件并单独按其协议分发，或自行实现替代方案。
3. **引入任何第三方代码时，必须同步更新本清单**，并保留其版权与协议声明。

### 历史记录

- **2026-08-27（v1.19.1 候选核对）**：本周期未引入或升级第三方组件，Composer 与 npm
  依赖清单均无变化。Blox 编辑器交互、站点资源可用性与发布上传门禁的变更均为项目源码，
  不产生额外许可证义务；清单内组件与生产依赖仍有真实加载点。

- **2026-08-26（v1.19.0 候选核对）**：本周期未引入或升级第三方组件，Composer 与 npm
  依赖清单均无变化。新增的 Blox 模板预览图由本项目模板生成，新增和修改的前端脚本均为
  项目源码，不产生额外许可证义务。反向核对：清单内前端组件、插件图标库与 PHP 生产
  依赖仍有真实加载点。

- **2026-09-03（v1.19.4 核对）**：本周期未引入或升级任何第三方组件。`composer.json` /
  `composer.lock`、`package.json` 与 `assets/` 下的第三方目录自 v1.19.3 起零改动（`git diff
  --stat v1.19.3..main` 核对）。新增的 `assets/js/blox-*.js` 五个脚本（banner-panel /
  catalog-source / home-content-panel / image-control / style-groups）与 `includes/FooterNavigation.php`、
  `includes/ThemeMarket.php` 等均为本项目源码，不产生许可义务。市场主题只改本项目自有模板源码，
  未引入外部资源。反向核对：本周期没有删除任何组件的加载点，v1.19.3 清单内组件的引用关系不变。

- **2026-08-30（v1.19.3 核对）**：本周期未引入任何新的第三方组件，PHP 生产依赖仍为空
  （`composer.json` 的 `require` 只剩 `php` 与 `ext-*` 平台约束，自 v1.19.2 自建拼音模块
  替换 overtrue/pinyin 后再无第三方库）。变动一处，不涉及授权变化：删除标志设计器
  `assets/icon-library/` 下与 `icons.bin` 完全冗余的 7618 个松散 SVG——SVG 本体自 v1.18.6
  起即以 `icons.bin` 形式分发，本次只是清掉重复的散件，`licenses/` 许可原文原样保留。
  反向核对：清单内前端组件、插件图标库与 PHP 生产依赖均仍有真实加载点；
  本轮新增的演示配图由本项目脚本生成（`tools/generate_demo_images.php`），不产生许可义务。

- **2026-08-23（v1.18.6 核对）**：本周期未引入任何新的第三方组件。变动两处，均不涉及
  授权变化：标志设计器移出核心安装包改由插件市场分发（图标库许可原文随插件包，见上），
  其 SVG 图标改为 `icons.bin` 索引化存放；`assets/bootstrap-icons/fonts/` 删除 `.woff`
  仅保留 `.woff2`（同一组件的字体格式取舍）。反向核对：清单内组件在代码中均有真实引用。

- **2026-08-19（清理未用组件）**：移除 `assets/aos/`（库从未被加载；`data-aos` 属性由自研
  `assets/js/scroll-anim.js` 消费，属性保留）与 `assets/wangeditor/`（`initWangEditor`
  自迁移 TinyMCE 起即为兼容门面，库本体从未加载）。**约定：不再使用的组件随发现即删，
  发版核对本清单时同时核对「清单有而代码未引用」的反向项。**

- **2026-08-19（v1.18.1 核对）**：补登 v1.18 周期随包新增的组件——D3.js 7.9.0（ISC）、
  d3-flextree（WTFPL）、d3-org-chart（MIT）（组织架构图元素）；Bootstrap Icons 1.13.1
 （MIT，此前已随包但漏登）；LOGO 工坊内置 Phosphor/Tabler SVG 图标库（均 MIT，许可
  原文随包）。逐一核对随包 LICENSE 原文，均为宽松协议，无 copyleft 引入。
  同周期移除的 icon-maker 插件无单独登记项，无需删改。

- **2026-08-03**：移除 `includes/html-api/`（源自 WordPress 核心的 HTML Tag Processor，
  GPLv2+；且随包副本缺失字符实体依赖，属性值含 `&amp;` 等命名实体时触发致命错误，
  会导致正文渲染白屏）。改由自研 `includes/HtmlTagRewriter.php` 承担，
  行为经 48 组用例逐字节对拍确认等价，并新增 13 项单元测试锁定边界。
