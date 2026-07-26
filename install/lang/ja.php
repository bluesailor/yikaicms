<?php
/**
 * Yikai CMS インストーラー — 日本語言語パック
 */

return [
    // 共通
    'lang_name' => '日本語',
    'title' => 'Yikai CMS インストール',
    'prev' => '前へ',
    'next' => '次へ',
    'finish' => 'インストール実行',
    'retry' => '再試行',

    // ステップ
    'step1' => '環境チェック',
    'step2' => 'データベース設定',
    'step3' => '管理者設定',
    'step4' => 'インストール完了',

    // 環境チェック
    'env_check' => '環境チェック',
    'env_php_version' => 'PHP バージョン',
    'env_required' => '必要',
    'env_current' => '現在',
    'env_status' => '状態',
    'env_pass' => 'OK',
    'env_fail' => 'NG',
    'env_pdo' => 'PDO 拡張',
    'env_pdo_mysql' => 'PDO MySQL 拡張',
    'env_pdo_sqlite' => 'PDO SQLite 拡張',
    'env_json' => 'JSON 拡張',
    'env_mbstring' => 'Mbstring 拡張',
    'env_openssl' => 'OpenSSL 拡張',
    'env_fileinfo' => 'Fileinfo 拡張',
    'env_gd' => 'GD 拡張',
    'env_dir_writable' => 'ディレクトリ書き込み権限',
    'env_required_ext' => '必須',
    'env_optional_ext' => '推奨',
    'env_writable' => '書き込み可',
    'env_not_writable' => '書き込み不可',
    'env_not_found' => 'ディレクトリが見つかりません',
    'env_check_fail' => '環境チェックに問題があります。上記の項目を解決してください。',

    // データベース設定
    'db_config' => 'データベース設定',
    'db_type' => 'データベースの種類',
    'db_mysql' => 'MySQL データベース',
    'db_sqlite' => 'SQLite データベース',
    'db_mysql_desc' => '本番環境向け、MySQL 5.7以上が必要',
    'db_sqlite_desc' => '軽量デプロイ、追加のDBサーバー不要',
    'db_host' => 'ホスト名',
    'db_port' => 'ポート',
    'db_name' => 'データベース名',
    'db_user' => 'ユーザー名',
    'db_pass' => 'パスワード',
    'db_prefix' => 'テーブル接頭辞',
    'db_test' => '接続テスト',
    'db_test_success' => '接続成功',
    'db_test_fail' => '接続失敗',
    'db_create_new' => 'データベースが存在しない場合は作成を試みる',

    // 管理者設定
    'admin_config' => '管理者設定',
    'admin_user' => '管理者ユーザー名',
    'admin_pass' => '管理者パスワード',
    'admin_pass_confirm' => 'パスワード確認',
    'admin_email' => '管理者メールアドレス',
    'site_name' => 'サイト名',
    'site_url' => 'サイトURL',
    'site_lang_label' => 'フロントエンド言語',
    'site_lang_tip' => '訪問者に表示される言語',
    'admin_lang_label' => '管理画面言語',
    'admin_lang_tip' => '管理者ログイン後の言語',
    'install_demo_label' => 'デモデータをインポート',
    'install_demo_tip' => '初期記事・サンプル製品などを含む（後から削除可能）',
    'admin_user_tip' => '半角英数4〜20文字',
    'admin_pass_tip' => '6文字以上',
    'password_mismatch' => 'パスワードが一致しません',

    // インストール中
    'installing' => 'インストール中...',
    'install_create_db' => 'データベース構造を作成',
    'install_init_data' => '初期データを投入',
    'install_create_admin' => '管理者アカウントを作成',
    'install_write_config' => '設定ファイルを書き出し',
    'install_success' => 'インストール成功',
    'install_fail' => 'インストール失敗',

    // 完了
    'install_complete' => 'インストール完了',
    'install_complete_desc' => 'Yikai CMS のインストールが完了しました。管理者情報は大切に保管してください。',
    'goto_admin' => '管理画面へ',
    // ワンクリック高速インストール（SQLite） + 完了ページ案内
    'quick_title' => 'ワンクリック高速インストール（SQLite・設定不要）',
    'quick_desc' => 'データベース情報の入力は不要。内蔵の SQLite（デモデータ含む）で数秒でインストールできます。お試しに最適です。',
    'quick_button' => '⚡ 高速インストール',
    'quick_confirm' => 'SQLite（デモデータ含む）で高速インストールします。管理者ユーザーは「admin」、パスワードは自動生成され完了後に表示されます。続行しますか？',
    'quick_installing' => 'インストール中…',
    'quick_done' => 'インストール完了！',
    'quick_account' => '管理者ユーザー',
    'quick_password' => 'パスワード',
    'quick_warn' => 'すぐにログインしてパスワードを変更してください。パスワードは一度だけ表示されます。',
    'install_cta_hint' => 'ログイン後、ダッシュボードの案内に従えば数秒でサイトのカテゴリを生成できます。',
    'goto_home' => 'サイトトップへ',
    'security_tip' => 'セキュリティのため、install ディレクトリを削除してください。',

    // Rewrite設定ガイド
    'rewrite_title' => 'URLリライト設定',
    'rewrite_desc' => 'きれいなURL（/company.html 等）を使うにはWebサーバーの設定が必要です',
    'rewrite_apache_ok' => '設定不要',
    'rewrite_apache_desc' => '.htaccess ファイルが同梱されています。mod_rewrite が有効であれば自動的に動作します。',
    'rewrite_apache_check' => 'Apache の httpd.conf で AllowOverride All が設定されていることを確認してください。',
    'rewrite_nginx_wp_title' => 'WordPress のルールと互換',
    'rewrite_nginx_wp' => 'BTパネル等：リライト設定で「wordpress」プリセットを選ぶだけで動作します。',
    'rewrite_nginx_desc' => 'プリセットがない場合は、server ブロックに次の 1 行を追加してください：',
    'rewrite_nginx_advanced' => '上級者向け（静的 HTML 直接配信＋サーバー側の保護）はリポジトリの deploy/nginx-server.conf を参照。',
    'rewrite_nginx_reload' => '設定後、nginx -t で構文チェックし、nginx -s reload で反映してください。',

    // エラー
    'error_already_installed' => 'インストール済みです。再インストールするには installed.lock を削除してください。',
    'error_php_version' => 'PHP バージョンが古いです。8.0以上が必要です。',
    'error_dir_not_writable' => 'ディレクトリに書き込めません：',
    'error_db_connect' => 'データベース接続に失敗しました：',
    'error_db_create' => 'データベースの作成に失敗しました：',
    'error_sql_execute' => 'SQLの実行に失敗しました：',
    'error_admin_create' => '管理者の作成に失敗しました：',
    'error_config_write' => '設定ファイルの書き出しに失敗しました',
];
