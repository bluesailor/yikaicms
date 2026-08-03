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
| AOS | — | MIT | https://michalsnik.github.io/aos/ |
| flatpickr | 4.6.13 | MIT | https://flatpickr.js.org |
| PhotoSwipe | 5.4.4 | MIT | https://photoswipe.com |
| Plyr | — | MIT | https://plyr.io |
| qrcode.js | — | MIT | https://github.com/davidshimjs/qrcodejs |
| wangEditor | — | MIT | https://www.wangeditor.com |
| Tabler Icons | 3.44.0 | MIT | https://tabler.io/icons |

## PHP 依赖（Composer，随包只保留生产依赖）

| 组件 | 版本 | 协议 | 项目地址 |
|---|---|---|---|
| overtrue/pinyin | 4.1.0 | MIT | https://github.com/overtrue/pinyin |
| composer 运行时组件（autoload） | — | MIT | https://github.com/composer |

开发依赖（PHPUnit、Psalm、PHP-Parser 等）不随发行包分发，见 `composer.json` 的
`require-dev`。

## 维护约定

1. **升级任何组件的大版本前，先核对其协议是否变更。**
   例：TinyMCE 自 7.0 起改为 GPLv2+，本项目因此钉在 6.x，不得自动升级。
2. **不得引入与本软件许可协议冲突的组件。**
   具体而言：GPL / AGPL 及其他 copyleft 协议的代码**不得并入本软件本体**——
   本软件的许可协议包含「不得再分发」等限制条款，与 copyleft 的传染性要求直接冲突。
   如确需此类能力，应改为可选插件并单独按其协议分发，或自行实现替代方案。
3. **引入任何第三方代码时，必须同步更新本清单**，并保留其版权与协议声明。

### 历史记录

- **2026-08-03**：移除 `includes/html-api/`（源自 WordPress 核心的 HTML Tag Processor，
  GPLv2+；且随包副本缺失字符实体依赖，属性值含 `&amp;` 等命名实体时触发致命错误，
  会导致正文渲染白屏）。改由自研 `includes/HtmlTagRewriter.php` 承担，
  行为经 48 组用例逐字节对拍确认等价，并新增 13 项单元测试锁定边界。
