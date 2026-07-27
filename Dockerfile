# YikaiCMS 官方镜像 —— PHP 8.2 + Apache（.htaccess 伪静态开箱即用）
# 构建：docker build -t yikaicms:latest .
# 详见 docs/docker.md（含推送阿里云容器镜像服务 ACR 步骤）
FROM php:8.2-apache

# ---- 系统依赖 + PHP 扩展 ----
# gd(图像/图标工坊) zip(安装包/升级) pdo_mysql(数据库) mbstring exif opcache
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip mbstring exif opcache; \
    apt-get clean; rm -rf /var/lib/apt/lists/*

# ---- Apache：启用 mod_rewrite + 允许 .htaccess ----
RUN a2enmod rewrite; \
    printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
      > /etc/apache2/conf-available/yikaicms.conf; \
    a2enconf yikaicms

# ---- opcache 生产配置 ----
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=60'; \
    } > /usr/local/etc/php/conf.d/opcache.ini; \
    { \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=25M'; \
      echo 'max_execution_time=300'; \
      echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/yikaicms.ini

# ---- Composer 依赖（仅运行时；复制清单先装，利用层缓存）----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader --no-progress

# ---- 应用代码 ----
COPY . .

# 可写目录归属 www-data（安装器写 config/config.php；storage/uploads 运行时写入）
RUN chown -R www-data:www-data storage uploads config

EXPOSE 80
# 健康检查：首页或安装页可响应即视为就绪
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/") !== false || @file_get_contents("http://127.0.0.1/install/") !== false ? 0 : 1);'
