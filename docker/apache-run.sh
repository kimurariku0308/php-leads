#!/bin/sh
set -e
PORT="${PORT:-8080}"
sed -ri "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
exec apache2-foreground
