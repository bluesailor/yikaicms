---
name: deploy-site
description: Deploy project files to remote server via FTP/lftp. Reads FTP credentials from docs/ftp_info.md, packages files, uploads, and optionally runs post-deploy scripts. Use when the user says "deploy", "upload to server", "publish", "push to production".
argument-hint: "[project-dir] [--files file1,file2] [--full]"
user-invocable: true
---

# Deploy Site

Deploy files from a local project to its remote server.

## Steps

1. **Identify the project.** If `$ARGUMENTS` contains a project directory, use it. Otherwise use the current working directory. Supported projects and their local paths:
   - ikaicms.yikai → `D:\phpstudy_pro\WWW\ikaicms.yikai`
   - ikai.cms → `D:\phpstudy_pro\WWW\ikai.cms`
   - lumisign.yikai → `D:\phpstudy_pro\WWW\lumisign.yikai`
   - ht-sshc.yikai → `D:\phpstudy_pro\WWW\ht-sshc.yikai`
   - demo.yikaicms.com → `D:\phpstudy_pro\WWW\ikaicms.yikai` (deployed to demo server)

2. **Read FTP credentials** from `docs/ftp_info.md` in the project directory. Extract host, username, password, port, and upload path.

3. **Determine what to deploy:**
   - `--full`: Package entire project as zip (exclude .git, docs, storage/logs, config/config.php, installed.lock, node_modules, vendor, *.bak, admin/bigdump*)
   - `--files file1,file2`: Upload specific files only
   - No flag: Ask the user what to deploy

4. **Upload via lftp** (preferred) or curl:
   ```
   lftp << EOF
   set ftp:ssl-allow no
   set ftp:passive-mode yes
   open HOST
   user "USERNAME" "PASSWORD"
   cd REMOTE_PATH
   put LOCAL_FILE -o REMOTE_FILE
   bye
   EOF
   ```
   For full deploys, upload a zip + unzip PHP script, then tell the user to run the unzip URL.

5. **Post-deploy:** If deploying to a fresh site, remind about:
   - Creating config/config.php (offer to generate from config.sample.php)
   - Importing database
   - Creating installed.lock
   - Recompiling Tailwind CSS if theme files changed

6. **Cleanup:** Remove temporary zip files and deployment scripts from server after confirmation.

## Important
- NEVER upload config/config.php (contains credentials)
- NEVER upload installed.lock
- Always use `set ftp:ssl-allow no` for lftp connections
- If lftp fails, try curl with --ftp-pasv or netrc file for special characters in passwords
- After uploading theme PHP files, remind to recompile Tailwind CSS
