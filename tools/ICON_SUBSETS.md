# 设计变量与前台图标子集

## 设计变量的单一来源

W8 不新增另一套变量表。现有实现已经形成完整纵切：

- 颜色变量与命名样式唯一存于 `settings.blox_design_system`，由
  `BloxDesignSystem` 解码、校验、保存和输出；`primary_color` / `secondary_color`
  仍是基础设置的唯一所有者，只投影为锁定系统变量，不能被 JSON 重名覆盖。
- 字体、按钮和布局变量唯一存于 `blox_design_theme_draft` / `blox_design_theme`，
  由 `BloxDesignTheme` 负责草稿预览、发布与编译。不要把这些字段再复制进
  `blox_design_system`。
- 主题包的 `design-tokens.json` 只提供安装默认值；站点编辑后以 settings 为准，
  主题升级不能覆盖用户值。

后台入口是「易开网页构建器 → 全站设计」。API 使用 revision 乐观锁并按字段白名单
校验；预览读取草稿，前台只读取已发布主题。颜色变量通过
`<style id="yk-blox-design-tokens">` 输出为 `--yk-color-*`，主题变量通过
`<style id="yk-blox-design-theme">` 输出。未配置时使用类内种子默认值；颜色和命名样式
采用归档/恢复，不直接删除仍可能被文档引用的变量。保存走 `SettingModel` 的
`setting_saved` / `data_changed` 缓存失效链，快照缓存键含原始设置和基础色，写入后自然换键。

界面文案必须继续使用 `lang/{zh-CN,en,ja}.php` 中已有的 `blox_design_*` 与
`blox_design_theme_*` 键；新增控件须三语同步。

## 前台图标加载边界

后台和 Blox 编辑器继续加载完整 Tabler/Bootstrap 图标 CSS，插件管理界面不会因前台
优化缺图。公开主题只固定加载：

```text
/assets/icons/site-icons.min.css
```

该文件包含仓库前台源码扫描到的图标，加上 `config/icon-subset.php` 的显式动态
safelist。Blox 文档、站点设置或插件数据若使用子集外图标，`BloxIcon` 会通过
`BloxAssetCollector` 按需加载对应完整 CSS；旧内容保持可见，普通页面不再默认下载两套
完整图标表。插件直接拼接动态图标 class 时，应优先调用 `BloxIcon::classes()`；确实需要
固定驻留前台的动态图标则加入 safelist。

## 可重复构建与审计

构建依赖只用于开发机，不进入 PHP 运行环境：Python、`fonttools==4.60.1` 和
`brotli==1.1.0`。

```bash
python -m pip install "fonttools==4.60.1" "brotli==1.1.0"
php tools/build-icon-subsets.php
php tools/build-icon-subsets.php --check
```

若 Python 不在 `PATH`，设置 `PYTHON_BIN`。构建器会：

1. 从上游完整 CSS 读取图标名和 Unicode；
2. 扫描配置的前台目录并合并显式 safelist；
3. 任一引用在上游不存在时立即失败；
4. 用 FontTools 生成确定性的 WOFF2 子集；
5. 写入 `assets/icons/site-icon-audit.json`，记录来源哈希、扫描结果、safelist、最终清单、
   产物大小和 SHA-256。

`--check` 不要求安装 FontTools，它会验证引用、CSS 内容、审计清单和现有产物哈希，适合
CI/发版前门禁。升级 Tabler/Bootstrap 或新增动态图标后必须重建并提交 CSS、WOFF2 和审计
报告；不得手改生成物。
