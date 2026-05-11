---
name: new-site
description: Create a new website project based on ikaiCMS. Copies the framework, creates database, configures settings, and sets up the initial theme. Use when the user says "create new site", "new project", "set up website for".
argument-hint: "<domain> [--lang ja|zh-CN] [--prefix prefix_] [--db dbname]"
user-invocable: true
---

# New Site Setup

Create a new ikaiCMS website from scratch.

## Steps

1. **Parse arguments:**
   - Domain/directory name (e.g., `example.yikai`)
   - Language: `ja` (default, use ikai.cms as base) or `zh-CN` (use ikaicms.yikai as base)
   - Table prefix: default `yikai_`, can customize (e.g., `jp_yikai_`)
   - Database name: auto-generate from domain if not specified

2. **Copy framework files:**
   ```bash
   rsync -av --exclude='.git' --exclude='storage/database.sqlite' \
     --exclude='config/config.php' --exclude='installed.lock' \
     --exclude='uploads/images/*' --exclude='docs/' \
     --exclude='admin/bigdump*' \
     SOURCE/ TARGET/
   ```
   Source: `ikai.cms` for Japanese, `ikaicms.yikai` for Chinese.

3. **Create MySQL database:**
   ```bash
   MYSQL="/mnt/d/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysql.exe"
   $MYSQL -u root -p123456 -e "CREATE DATABASE dbname DEFAULT CHARACTER SET utf8mb4;"
   ```

4. **Import install SQL** with correct prefix:
   ```bash
   sed 's/yikai_/PREFIX/g' install/sql/mysql.sql | $MYSQL -u root -p123456 dbname
   ```

5. **Create config.php** from config.sample.php template:
   - Fill in DB credentials, site name, site URL, language
   - Generate random SESSION_NAME and ENCRYPT_KEY
   - Set correct timezone (Asia/Tokyo for ja, Asia/Shanghai for zh-CN)
   - **CRITICAL:** Include all path defines and `require_once CONFIG_PATH . 'database.php'`

6. **Create admin user:**
   ```bash
   PHP="/mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
   PASS=$($PHP -r "echo password_hash('admin888', PASSWORD_BCRYPT);")
   ```

7. **Update site settings** in database (site_name, site_url, site_lang)

8. **Create installed.lock**

9. **Compile Tailwind CSS:**
   ```bash
   /mnt/d/phpstudy_pro/WWW/tailwindcss-windows-x64.exe \
     -i assets/css/input.css -o assets/css/tailwind.css --minify
   ```

10. **Test** by opening in browser.

## Important
- Always use config.sample.php as template — it has all required defines and database.php loading
- Default admin credentials: admin / admin888
- If creating a custom theme, compile Tailwind CSS after adding theme files
- Save FTP credentials to docs/ftp_info.md if provided
