---
name: sync-fix
description: Sync a bug fix or code change across all ikaiCMS sister projects. When a bug is found and fixed in one project, this skill applies the same fix to all related projects. Use when the user says "sync this fix", "apply to other projects", "fix in all projects".
argument-hint: "<file-path> [--projects all|ikai,lumisign,ikaicms,htsshc]"
user-invocable: true
---

# Sync Fix Across Projects

Apply a code fix from one project to all sister projects.

## Project Registry

| Alias | Path | Language | DB Prefix |
|-------|------|----------|-----------|
| ikaicms | D:\phpstudy_pro\WWW\ikaicms.yikai | zh-CN | yikai_ |
| ikai | D:\phpstudy_pro\WWW\ikai.cms | ja | yikai_ |
| lumisign | D:\phpstudy_pro\WWW\lumisign.yikai | ja | yikai_ |
| htsshc | D:\phpstudy_pro\WWW\ht-sshc.yikai | ja | jp_yikai_ |

## Steps

1. **Identify the fix.** Read the file that was just modified. Understand what changed and why.

2. **Find the same file in other projects.** Check if the file exists at the same relative path in each sister project.

3. **Check if the bug exists.** Use grep to verify the same problematic pattern exists before applying the fix. Don't blindly overwrite — the file may have diverged.

4. **Apply the fix.** Use the same Edit operation (sed, Edit tool, or manual) to apply the identical fix. If the surrounding code differs, adapt the fix to match.

5. **Report results.** Show which projects were fixed, which were already clean, and which had conflicts.

## Common Bug Patterns to Watch For
- `'<?php echo __(' inside PHP strings/comments (nested PHP tags)
- `$var['key'] ?? fallback` vs `$var['key'] ?: fallback` (null vs falsy)
- Missing `?? 0` for undefined array keys
- `SHOW CREATE TABLE` without SQLite compatibility
- Hardcoded Chinese in Japanese project files
- Missing `__()` translation wrappers

## Important
- Always verify the bug exists before fixing — don't assume all projects have the same code
- For language-specific files (lang/*.php), don't sync content — only sync structural fixes
- After syncing admin/ files, remind about uploading to production servers
