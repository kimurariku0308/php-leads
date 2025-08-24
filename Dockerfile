FROM php:8.3-apache

# PGSQL拡張
RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_mysql mysqli pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# .htaccess 有効化
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# アプリをイメージに同梱（Koyebはボリュームを使わないため）
WORKDIR /var/www/html
COPY app/public/ /var/www/html/
COPY app/config/ /var/www/config/

# Koyebの $PORT に合わせてApacheのListenを書き換えるエントリポイント
COPY docker/apache-run.sh /usr/local/bin/apache-run.sh
RUN chmod +x /usr/local/bin/apache-run.sh
CMD ["apache-run.sh"]
