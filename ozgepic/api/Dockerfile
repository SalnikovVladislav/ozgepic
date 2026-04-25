FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx supervisor curl \
 && docker-php-ext-install pdo pdo_mysql \
 && mkdir -p /run/nginx /var/log/kese

COPY docker/nginx.conf        /etc/nginx/nginx.conf
COPY docker/supervisord.conf  /etc/supervisord.conf

WORKDIR /var/www/html
COPY public /var/www/html/public
COPY src    /var/www/html/src

RUN chown -R www-data:www-data /var/www/html /var/log/kese

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

