# 使用官方提供的 PHP 8.2 + Apache 映像檔
FROM php:8.2-apache

# 安裝連線資料庫（如 TiDB/MySQL）所需的擴充功能
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 將你目前的網頁程式碼全部複製進去環境中
COPY . /var/www/html/

# 設定正確的讀取權限
RUN chown -R www-data:www-data /var/www/html/

# 開放 80 埠位供外部連線
EXPOSE 80