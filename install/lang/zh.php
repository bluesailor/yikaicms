<?php
/**
 * Yikai CMS 安装器 - 中文语言包
 */

return [
    // 通用
    'lang_name' => '简体中文',
    'title' => 'Yikai CMS 安装向导',
    'prev' => '上一步',
    'next' => '下一步',
    'finish' => '完成安装',
    'retry' => '重试',

    // 步骤
    'step1' => '环境检测',
    'step2' => '数据库配置',
    'step3' => '管理员设置',
    'step4' => '安装完成',

    // 环境检测
    'env_check' => '环境检测',
    'env_php_version' => 'PHP 版本',
    'env_required' => '要求',
    'env_current' => '当前',
    'env_status' => '状态',
    'env_pass' => '通过',
    'env_fail' => '不通过',
    'env_pdo' => 'PDO 扩展',
    'env_pdo_mysql' => 'PDO MySQL 扩展',
    'env_pdo_sqlite' => 'PDO SQLite 扩展',
    'env_json' => 'JSON 扩展',
    'env_mbstring' => 'Mbstring 扩展',
    'env_openssl' => 'OpenSSL 扩展',
    'env_fileinfo' => 'Fileinfo 扩展',
    'env_gd' => 'GD 扩展',
    'env_dir_writable' => '目录可写',
    'env_required_ext' => '必需',
    'env_optional_ext' => '可选',
    'env_writable' => '可写',
    'env_not_writable' => '不可写',
    'env_not_found' => '目录不存在',
    'env_check_fail' => '环境检测未通过，请先解决以上问题',

    // 数据库配置
    'db_config' => '数据库配置',
    'db_type' => '数据库类型',
    'db_mysql' => 'MySQL 数据库',
    'db_sqlite' => 'SQLite 数据库',
    'db_mysql_desc' => '适合正式环境，需要 MySQL 5.7+',
    'db_sqlite_desc' => '轻量部署，无需额外数据库',
    'db_host' => '数据库主机',
    'db_port' => '端口',
    'db_name' => '数据库名',
    'db_user' => '用户名',
    'db_pass' => '密码',
    'db_prefix' => '表前缀',
    'db_test' => '测试连接',
    'db_test_success' => '连接成功',
    'db_test_fail' => '连接失败',
    'db_create_new' => '如果数据库不存在，尝试创建',

    // 管理员设置
    'admin_config' => '管理员设置',
    'admin_user' => '管理员账号',
    'admin_pass' => '管理员密码',
    'admin_pass_confirm' => '确认密码',
    'admin_email' => '管理员邮箱',
    'site_name' => '站点名称',
    'site_url' => '站点URL',
    'admin_user_tip' => '4-20位字母数字',
    'admin_pass_tip' => '至少6位',
    'password_mismatch' => '两次密码不一致',

    // 安装中
    'installing' => '正在安装...',
    'install_create_db' => '创建数据库结构',
    'install_init_data' => '初始化数据',
    'install_create_admin' => '创建管理员账号',
    'install_write_config' => '写入配置文件',
    'install_success' => '安装成功',
    'install_fail' => '安装失败',

    // 完成
    'install_complete' => '安装完成',
    'install_complete_desc' => 'Yikai CMS 已成功安装，请妥善保管管理员账号信息',
    'goto_admin' => '进入后台',
    'goto_home' => '访问首页',
    'security_tip' => '安全提示：请删除 install 目录',

    // 错误
    'error_already_installed' => '系统已安装，如需重新安装请删除 config/installed.lock 文件',
    'error_php_version' => 'PHP 版本过低，需要 8.0 或更高版本',
    'error_dir_not_writable' => '目录不可写：',
    'error_db_connect' => '数据库连接失败：',
    'error_db_create' => '创建数据库失败：',
    'error_sql_execute' => '执行 SQL 失败：',
    'error_admin_create' => '创建管理员失败：',
    'error_config_write' => '写入配置文件失败',
];
