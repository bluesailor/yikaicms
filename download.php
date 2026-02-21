<?php
/**
 * Yikai CMS - 下载处理器
 *
 * - fid 参数：从 yikai_downloads 表下载（主要方式）
 * - id  参数：从 yikai_contents 表下载（兼容旧链接）
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$fid = getInt('fid');
$id = getInt('id');

// ========== yikai_downloads 表（主要） ==========
if ($fid > 0) {
    $file = downloadModel()->find($fid);
    if (!$file || empty($file['file_url']) || (int)$file['status'] !== 1) {
        header('HTTP/1.1 404 Not Found');
        exit('文件不存在');
    }

    // 检查单条下载的登录限制
    if (!empty($file['require_login']) && !isMemberLoggedIn()) {
        $loginUrl = '/member/login.php?redirect=' . urlencode('/download.php?fid=' . $fid);
        redirect($loginUrl);
    }

    downloadModel()->incrementDownloads($fid);
    header('Location: ' . $file['file_url']);
    exit;
}

// ========== yikai_contents 表（兼容旧链接） ==========
if ($id > 0) {
    $content = contentModel()->find($id);
    if ($content && !empty($content['attachment'])) {
        // 检查全局下载登录限制
        if (config('download_require_login') === '1' && !isMemberLoggedIn()) {
            $loginUrl = '/member/login.php?redirect=' . urlencode('/download.php?id=' . $id);
            redirect($loginUrl);
        }

        addDownloadCount($id);
        header('Location: ' . $content['attachment']);
        exit;
    }
}

header('HTTP/1.1 404 Not Found');
exit('文件不存在');
