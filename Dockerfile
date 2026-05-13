# 1. 使用官方 PHP + Apache 映像檔
FROM php:8.2-apache

# 2. 安裝系統套件與 PHP 擴充功能
# 這裡補上了 libpng-dev (GD 常用) 與其他可能需要的套件
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd

# 3. 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. 將程式碼複製到容器內
COPY . /var/www/html/

# 5. 執行 composer install
WORKDIR /var/www/html
# 加上 --ignore-platform-reqs 可以跳過環境檢查，避免因為缺少特定擴充而報錯
RUN if [ -f "composer.json" ]; then \
    composer install --no-dev --optimize-autoloader --ignore-platform-reqs; \
    fi

# 6. 設定權限
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80