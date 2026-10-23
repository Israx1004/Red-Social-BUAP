# UniLink — imagen PHP para Apache y para el worker de cola
FROM php:8.2-apache

# pdo_mysql (base de datos), pcntl (apagado limpio del worker), redis (rate limit / cola)
RUN docker-php-ext-install pdo_mysql pcntl \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && a2enmod rewrite headers proxy proxy_wstunnel

# Subidas de hasta 10 MB (límite de uploadFile)
RUN { echo 'upload_max_filesize=10M'; echo 'post_max_size=45M'; echo 'expose_php=Off'; } \
    > /usr/local/etc/php/conf.d/unilink.ini
