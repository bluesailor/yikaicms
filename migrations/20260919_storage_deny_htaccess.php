<?php
/**
 * 为已有站点补上 storage/.htaccess（拒绝一切网页访问）。
 *
 * storage/ 存放 SQLite 数据库、日志、缓存、备份与升级文件，任何内容都不该经 HTTP 访问。
 * 新装站点由安装包带上该文件；但升级器把整个 storage/ 视为站点自有目录（UO_EXCLUDES），
 * 升级包不会写入其中，所以老站只能靠本迁移补齐。只在文件不存在时写入，不覆盖站点已有的同名文件。
 *
 * 只对 Apache 生效；Nginx 由 deploy/nginx-baota.conf / nginx-server.conf 在服务器层封禁 /storage/。
 * 迁移文件会被多次 require，所以不定义全局函数；内容与随包的 storage/.htaccess 逐字一致（单测校验）。
 */

declare(strict_types=1);

$storageDenyHtaccess = <<<'HTACCESS'
# YikaiCMS runtime data: SQLite database, logs, cache, backups and upgrade files.
# Nothing in storage/ is ever served over HTTP. Deny all direct web access.
# (Apache only. Nginx ignores this file; use deploy/nginx-baota.conf or
# deploy/nginx-server.conf, which block /storage/ at the server level.)

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HTACCESS;

return [
    'id' => '20260919_storage_deny_htaccess',
    'title' => '为运行数据目录补上访问封禁',
    'desc' => '在 storage/ 写入拒绝网页访问的 .htaccess，防止 Apache 主机上 SQLite 数据库、日志、备份被直接下载；已有同名文件时不覆盖。',
    'title_en' => 'Deny web access to the runtime data directory',
    'title_ja' => '実行データディレクトリへの Web アクセスを遮断',
    'desc_en' => 'Writes a deny-all .htaccess into storage/ so that the SQLite database, logs and backups cannot be downloaded on Apache hosts. An existing file with the same name is left unchanged.',
    'desc_ja' => 'storage/ に Web アクセスを拒否する .htaccess を書き込み、Apache ホストで SQLite データベース・ログ・バックアップが直接ダウンロードされるのを防ぎます。同名のファイルが既にある場合は変更しません。',
    'htaccess' => $storageDenyHtaccess,
    'check' => static function (): bool {
        return is_file(ROOT_PATH . '/storage/.htaccess') || !is_dir(ROOT_PATH . '/storage');
    },
    'php' => static function () use ($storageDenyHtaccess): string {
        $file = ROOT_PATH . '/storage/.htaccess';
        if (is_file($file)) {
            return 'storage/.htaccess 已存在，保留现有内容。';
        }
        if (file_put_contents($file, $storageDenyHtaccess, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 storage/.htaccess，请检查 storage/ 目录的写权限，或从安装包复制该文件。');
        }
        return '已写入 storage/.htaccess，Apache 主机上 storage/ 不再可经网页访问。';
    },
];
