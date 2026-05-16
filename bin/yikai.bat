@echo off
REM Yikai CMS CLI entry point (Windows).
REM Delegates to bin/yikai.php under php.exe in PATH.
php "%~dp0yikai.php" %*
