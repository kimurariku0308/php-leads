FROM php:8.3-apache

# PG/MySQL両対応（後方互換）
RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo pdo_mysql mysqli pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY app/public/ /var/www/html/
COPY app/config/ /var/www/config/

COPY docker/apache-run.sh /usr/local/bin/apache-run.sh
RUN chmod +x /usr/local/bin/apache-run.sh
CMD ["apache-run.sh"]
