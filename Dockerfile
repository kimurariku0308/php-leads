FROM php:8.3-apache

# MySQL/PGSQL両対応（将来Koyeb/Postgresに載せ替え可能に）
RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_mysql mysqli pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# URL書き換え用
RUN a2enmod rewrite

WORKDIR /var/www/html
