@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

:: ============================================================
:: Yikai CMS - 一键打包脚本
:: 使用 xcopy 安全复制，构建目录在 %TEMP% 中避免冲突
:: ============================================================

:: 取得脚本所在目录（项目根目录）
set "PROJECT_DIR=%~dp0"
:: 去掉末尾反斜杠
if "%PROJECT_DIR:~-1%"=="\" set "PROJECT_DIR=%PROJECT_DIR:~0,-1%"

:: 读取版本号
set "VERSION="
for /f "tokens=4 delims='" %%a in ('findstr /c:"CMS_VERSION" "%PROJECT_DIR%\config\config.php"') do (
    set "VERSION=%%a"
)

if "%VERSION%"=="" (
    echo [错误] 无法从 config\config.php 读取 CMS_VERSION
    pause
    exit /b 1
)

echo ============================================================
echo   Yikai CMS 打包工具
echo   版本: v%VERSION%
echo   源目录: %PROJECT_DIR%
echo ============================================================
echo.

:: 设置路径 — 构建目录放在 TEMP 中，避免与源目录重叠
set "PACKAGE_NAME=yikaicms-v%VERSION%"
set "BUILD_DIR=%TEMP%\ikaicms_build"
set "BUILD_PKG=%BUILD_DIR%\%PACKAGE_NAME%"
set "RELEASE_DIR=%PROJECT_DIR%\releases"
set "ZIP_FILE=%RELEASE_DIR%\%PACKAGE_NAME%.zip"

:: 创建 releases 目录
if not exist "%RELEASE_DIR%" mkdir "%RELEASE_DIR%"

:: 清理旧的构建目录
if exist "%BUILD_DIR%" (
    echo [1/7] 清理旧构建目录...
    rmdir /s /q "%BUILD_DIR%"
)
mkdir "%BUILD_PKG%"

:: ============================================================
:: 创建排除列表文件
:: ============================================================
echo [2/7] 准备排除列表...

:: 排除的目录和文件模式
set "XCOPY_EXCLUDEDIR=%BUILD_DIR%\xcopy_exclude.txt"
(
    echo .claude\
    echo .git\
    echo node_modules\
    echo vendor\
    echo releases\
    echo docs\
    echo assets\css\src\
    echo .rar
    echo .bak
    echo .log
) > "%XCOPY_EXCLUDEDIR%"

:: ============================================================
:: 复制文件（使用 xcopy，只读不写源目录）
:: ============================================================
echo [3/7] 复制项目文件...
xcopy "%PROJECT_DIR%" "%BUILD_PKG%\" /E /I /Q /Y /EXCLUDE:%XCOPY_EXCLUDEDIR% >nul

if errorlevel 1 (
    echo [警告] xcopy 报告部分文件未复制，继续处理...
)

:: 验证复制结果
if not exist "%BUILD_PKG%\index.php" (
    echo [错误] 复制失败 - index.php 不存在于构建目录
    echo [清理] 删除构建目录...
    rmdir /s /q "%BUILD_DIR%"
    pause
    exit /b 1
)

echo        复制完成，验证通过

:: ============================================================
:: 清理构建目录中不需要的文件
:: ============================================================
echo [4/7] 清理不需要的文件...

:: 删除 config.php（保留 config.php.example）
if exist "%BUILD_PKG%\config\config.php" del /q "%BUILD_PKG%\config\config.php"

:: 删除开发/构建相关文件
if exist "%BUILD_PKG%\build.bat" del /q "%BUILD_PKG%\build.bat"
if exist "%BUILD_PKG%\.gitignore" del /q "%BUILD_PKG%\.gitignore"
if exist "%BUILD_PKG%\.gitattributes" del /q "%BUILD_PKG%\.gitattributes"
if exist "%BUILD_PKG%\psalm.xml" del /q "%BUILD_PKG%\psalm.xml"
if exist "%BUILD_PKG%\composer.json" del /q "%BUILD_PKG%\composer.json"
if exist "%BUILD_PKG%\composer.lock" del /q "%BUILD_PKG%\composer.lock"
if exist "%BUILD_PKG%\install.lock" del /q "%BUILD_PKG%\install.lock"
if exist "%BUILD_PKG%\installed.lock" del /q "%BUILD_PKG%\installed.lock"

:: 删除 tailwindcss 编译器
if exist "%BUILD_PKG%\tailwindcss" del /q "%BUILD_PKG%\tailwindcss"
if exist "%BUILD_PKG%\tailwindcss.exe" del /q "%BUILD_PKG%\tailwindcss.exe"
if exist "%BUILD_PKG%\tailwindcss-windows-x64.exe" del /q "%BUILD_PKG%\tailwindcss-windows-x64.exe"

:: 删除多余配置文件（保留 config.sample.php，安装程序需要它）
if exist "%BUILD_PKG%\config\installed.lock" del /q "%BUILD_PKG%\config\installed.lock"

:: 删除 SQLite 数据库文件
if exist "%BUILD_PKG%\storage\database.sqlite" del /q "%BUILD_PKG%\storage\database.sqlite"

:: 删除日志文件
if exist "%BUILD_PKG%\storage\logs" (
    del /q "%BUILD_PKG%\storage\logs\*.log" 2>nul
)

:: 删除旧版升级脚本（install 根目录下的 upgrade_* 文件）
del /q "%BUILD_PKG%\install\upgrade_*.*" 2>nul

:: ============================================================
:: 清空 uploads，保留目录结构
:: ============================================================
echo [5/7] 重建 uploads 目录结构...
if exist "%BUILD_PKG%\uploads" rmdir /s /q "%BUILD_PKG%\uploads"
mkdir "%BUILD_PKG%\uploads\images"
mkdir "%BUILD_PKG%\uploads\files"
mkdir "%BUILD_PKG%\uploads\videos"

:: 确保必要目录存在
if not exist "%BUILD_PKG%\storage\logs" mkdir "%BUILD_PKG%\storage\logs"
if not exist "%BUILD_PKG%\storage\cache" mkdir "%BUILD_PKG%\storage\cache"
if not exist "%BUILD_PKG%\storage\login_throttle" mkdir "%BUILD_PKG%\storage\login_throttle"

:: 创建空目录占位文件
echo. > "%BUILD_PKG%\uploads\images\.gitkeep"
echo. > "%BUILD_PKG%\uploads\files\.gitkeep"
echo. > "%BUILD_PKG%\uploads\videos\.gitkeep"
echo. > "%BUILD_PKG%\storage\logs\.gitkeep"
echo. > "%BUILD_PKG%\storage\cache\.gitkeep"
echo. > "%BUILD_PKG%\storage\login_throttle\.gitkeep"

:: ============================================================
:: 创建 ZIP 压缩包
:: ============================================================
echo [6/7] 创建 ZIP 压缩包...

:: 删除旧的 ZIP
if exist "%ZIP_FILE%" del /q "%ZIP_FILE%"

:: 使用 PowerShell 创建 ZIP
powershell -NoProfile -Command "Compress-Archive -Path '%BUILD_PKG%' -DestinationPath '%ZIP_FILE%' -Force"

if not exist "%ZIP_FILE%" (
    echo [错误] ZIP 压缩包创建失败
    rmdir /s /q "%BUILD_DIR%"
    pause
    exit /b 1
)

:: ============================================================
:: 生成 SHA256 校验和
:: ============================================================
echo [7/7] 生成校验和...
powershell -NoProfile -Command "$hash = (Get-FileHash '%ZIP_FILE%' -Algorithm SHA256).Hash.ToLower(); Set-Content -Path '%RELEASE_DIR%\%PACKAGE_NAME%.sha256' -Value (\"$hash  %PACKAGE_NAME%.zip\") -NoNewline"

:: 获取文件大小
for %%f in ("%ZIP_FILE%") do set "FILE_SIZE=%%~zf"

:: 清理构建目录
rmdir /s /q "%BUILD_DIR%"

echo.
echo ============================================================
echo   打包完成！
echo.
echo   文件: releases\%PACKAGE_NAME%.zip
echo   大小: %FILE_SIZE% bytes
echo   校验: releases\%PACKAGE_NAME%.sha256
echo ============================================================
echo.
pause
