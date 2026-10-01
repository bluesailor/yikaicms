# 翻译流水线（tools/i18n）

把待译文案导出成 JSONL 分片交给翻译模型，校验译文，再合回语言包。只读写语言包与指定目录，不连数据库，不进安装包。

| 命令 | 作用 |
|---|---|
| `php tools/i18n/status.php` | 各语言在核心（`lang/`）、插件（`plugins/*/lang/`）、安装器（`install/lang/`）三块的进度与过时数 |
| `php tools/i18n/export.php <code> [--scope=core\|plugins\|installer\|all] [--prefix=] [--size=400] [--out=DIR]` | 导出该语言尚未翻译的键，分片 `<code>-<scope>-NNN.jsonl` + `manifest.json` |
| `php tools/i18n/validate.php <code> <导出的.jsonl> <译好的.jsonl> [--glossary=tools/i18n/glossary/<code>.json]` | 逐行校验；有错误时退出码 1 |
| `php tools/i18n/merge.php <code> <译好的.jsonl>...` | 合入；原文在导出后改过的键跳过并列为 STALE |

- 原文是 `zh-CN`；`en`、`ja` 是人工维护的参考，`zh-TW` 由简体整页转换，这四种都不是翻译目标。
- 语言代码以 `includes/i18n/LanguageRegistry.php` 为准（ko、es、pt、fr、de、ru、it、tr、vi、id、th、ar）。
- 原文哈希记在 `tools/i18n/hashes/<code>.json`，原文改动后 `status.php` 会显示过时数，重新导出即只导出缺的键（过时的键需先从目标包删除或在合入时覆盖）。

## 分片格式

导出的每一行：

```json
{"file":"lang/ko.php","key":"home_title","zh":"首页","en":"Home","ja":"ホーム","note":"home page","src_hash":"ab12cd34ef56"}
```

译好的分片：**原样保留每行全部字段、行数和顺序**，只在每行末尾加一个 `"text"` 字段放译文。

## 校验规则

错误（整片不合格）：行数 / 顺序 / 键与导出件不一致；`text` 为空；占位符（`:name`、`%s`、`{site}`）集合不同；HTML 标签序列不同；非 UTF-8 或含控制字符；除日语外译文里出现汉字。

警告（提示复查）：换行数不同；比英文长 2.5 倍以上；与英文完全相同的行超过 3%；原文含术语表里的词而译文没用指定译法。

## 术语表

`tools/i18n/glossary-terms.json` 是核心产品术语（中文 + 英文 / 日文参考 + 说明）。每种语言先据此写 `tools/i18n/glossary/<code>.json`（`{"栏目": "…", "询盘": "…"}`），经人确认后再翻分片，validate 时用 `--glossary` 检查。
