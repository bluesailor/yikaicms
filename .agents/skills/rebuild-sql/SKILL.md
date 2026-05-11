---
name: rebuild-sql
description: Rebuild install SQL package from the current database. Exports table structures and data via mysqldump, cleans up for distribution (removes AUTO_INCREMENT, user data, logs). Use when the user says "rebuild install SQL", "update install package", "regenerate SQL".
argument-hint: "[project-name]"
user-invocable: true
---

# Rebuild Install SQL

Generate a clean install SQL file from the current database state.

## Steps

1. **Identify the project and database.**
   - ikaicms.yikai → DB: ikaicms, prefix: yikai_
   - ikai.cms → DB: ikai_cms, prefix: yikai_
   - ht-sshc.yikai → DB: ht_sshc, prefix: jp_yikai_

2. **Export with mysqldump:**
   ```bash
   MYSQLDUMP="/mnt/d/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysqldump.exe"
   $MYSQLDUMP -u root -p123456 DB_NAME \
     --add-drop-table --complete-insert --skip-comments \
     --skip-lock-tables --no-tablespaces \
     --ignore-table=DB.PREFIX_admin_logs \
     --ignore-table=DB.PREFIX_ai_logs \
     --ignore-table=DB.PREFIX_users \
     --ignore-table=DB.PREFIX_forms \
     --ignore-table=DB.PREFIX_media \
     | sed 's/ AUTO_INCREMENT=[0-9]*//' > /tmp/export.sql
   ```

3. **Add structure-only tables** (admin_logs, ai_logs, users, forms, media):
   ```bash
   $MYSQLDUMP -u root -p123456 DB_NAME --no-data --skip-comments \
     PREFIX_admin_logs PREFIX_ai_logs PREFIX_users PREFIX_forms PREFIX_media \
     | sed 's/ AUTO_INCREMENT=[0-9]*//' > /tmp/structure.sql
   ```

4. **Combine** with header:
   ```
   -- ikaiCMS Install SQL
   -- Generated: DATE
   SET NAMES utf8mb4;
   SET FOREIGN_KEY_CHECKS = 0;
   [structure-only tables]
   [data tables]
   SET FOREIGN_KEY_CHECKS = 1;
   ```

5. **Save** to `install/sql/mysql.sql`

6. **Verify** by test-importing into a temporary database.

7. **SQLite version:** If the project has `install/sql/sqlite.sql`, remind the user it also needs updating (manual conversion required for SQLite syntax differences).

## Important
- NEVER include user passwords or admin log data in install SQL
- Remove AUTO_INCREMENT values
- Settings table: consider removing the `id` column from INSERT to avoid duplicate ID issues
- Test the generated SQL by importing into a fresh database before committing
