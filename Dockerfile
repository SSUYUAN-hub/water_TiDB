# 1. 使用官方 PHP + Apache 映像檔
FROM php:8.3-apache

# 2. 安裝系統套件與 PHP 擴充功能
# 這裡補上了 libpng-dev (GD 常用) 與其他可能需要的套件
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip

# 3. 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. 【優化點】先複製套件清單
WORKDIR /var/www/html
COPY composer.json composer.lock* ./

# 5. 【優化點】先執行安裝 (這樣只要 composer.json 沒改，這步就會被快取，超級快！)
RUN if [ -f "composer.json" ]; then \
    composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs; \
    fi

# 6. 這裡才複製剩下的所有程式碼
COPY . /var/www/html/

# 7. 最後才完成 Autoload (因為程式碼複製進來了)
RUN if [ -f "composer.json" ]; then \
    composer dump-autoload --optimize; \
    fi

# 8. 設定權限
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80