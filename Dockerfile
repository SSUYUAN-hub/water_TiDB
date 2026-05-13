# 1. 使用官方 PHP + Apache 映像檔
FROM php:8.2-apache

# 2. 安裝系統套件與 PHP 擴充功能 (mysqli, pdo_mysql)
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && docker-php-ext-install mysqli pdo pdo_mysql

# 3. 安裝 Composer (這是解決 vendor/autoload.php 關鍵)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. 將程式碼複製到容器內
COPY . /var/www/html/

# 5. 在容器內執行 composer install (如果你的專案有 composer.json)
# 如果你沒有 composer.json，請跳過這行，但建議要有
WORKDIR /var/www/html
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

# 6. 設定權限
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80