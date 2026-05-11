---
name: php-scan
description: Scan all ikaiCMS projects for common PHP bugs and code quality issues. Checks for nested PHP tags in strings, undefined functions, SQLite compatibility issues, hardcoded text needing translation, and syntax errors. Use when the user says "scan for bugs", "check code quality", "find issues".
argument-hint: "[--project name] [--fix]"
user-invocable: true
---

# PHP Code Scanner

Scan ikaiCMS projects for common bugs and issues.

## Projects to Scan

- D:\phpstudy_pro\WWW\ikaicms.yikai
- D:\phpstudy_pro\WWW\ikai.cms
- D:\phpstudy_pro\WWW\lumisign.yikai
- D:\phpstudy_pro\WWW\ht-sshc.yikai

If `--project` specified, scan only that one.

## Checks to Run

### 1. Nested PHP Tags (Critical)
Pattern: `'<?php echo __('` inside PHP code blocks
```bash
grep -rn "'<?php echo __(" admin/*.php --include="*.php"
```
This causes parse errors. Fix by replacing with direct `__()` calls.

### 2. PHP Syntax Check
```bash
PHP="/mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
find admin/ -name "*.php" -exec $PHP -l {} \; 2>&1 | grep -v "No syntax"
```

### 3. Undefined Function Calls
Check for functions that exist in one project but not another:
- `configJsonLang()` — only in ikaicms.yikai, not ikai.cms
- `configLang()` — only in ikaicms.yikai
- `isMultiLangEnabled()` — only in ikaicms.yikai

### 4. publish_time Fallback
Pattern: `$item['publish_time']` without `?:` or `?? 0` fallback
```bash
grep -rn "\['publish_time'\]" --include="*.php" | grep -v "?: \|?? 0\|?? \$"
```

### 5. SQLite Compatibility
Pattern: `SHOW CREATE TABLE`, `SHOW TABLE STATUS` without `isSqlite()` check
```bash
grep -rn "SHOW CREATE TABLE\|SHOW TABLE STATUS" --include="*.php"
```

### 6. Hardcoded Chinese in Japanese Projects (ikai.cms, lumisign)
```bash
grep -Prn '[\x{4e00}-\x{9fff}]' admin/*.php | grep -v "__(\|echo \|//\|/\*\|lang\|comment"
```

### 7. Missing Translation Keys
Check if `__('key')` returns the key itself (untranslated):
```bash
grep -rn "__(" admin/*.php | grep -oP "__\('([^']+)'\)" | sort -u
```
Cross-reference with lang/*.php to find missing keys.

## Output Format

```
=== PROJECT_NAME ===
[CRITICAL] file:line — description
[WARNING]  file:line — description
[INFO]     file:line — description

Summary: X critical, Y warnings, Z info
```

## Auto-Fix Mode
If `--fix` is specified:
- Fix nested PHP tags automatically
- Fix publish_time fallbacks
- Report what was fixed

Always ask before auto-fixing. Show the diff first.
