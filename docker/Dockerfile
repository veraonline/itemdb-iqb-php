FROM php:7.4.19-apache-buster

LABEL maintainer="IQB Berlin"
LABEL version="1.0"
LABEL description="The PHP backend of the teststudio-lite"
LABEL license="MIT"

## dependencies
RUN apt-get update && \
    apt-get install -y libpq-dev zlib1g-dev libzip-dev unzip

# install php extensions
RUN docker-php-ext-install -j$(nproc) pdo_pgsql zip

# set up apache
RUN a2enmod rewrite
RUN a2enmod headers
RUN a2dissite 000-default
COPY docker/config/vhost.conf /etc/apache2/sites-available
RUN a2ensite vhost
RUN echo "ServerName localhost" >> /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# set up php
COPY docker/config/local.php.ini /usr/local/etc/php/conf.d/local.ini

# copy source code
COPY create /var/www/html/create
COPY php_authoring /var/www/html/php_authoring
COPY php_superadmin /var/www/html/php_superadmin
COPY vo_code /var/www/html/vo_code
COPY .htaccess /var/www/html/
COPY getStatus.php /var/www/html/
COPY login.php /var/www/html/
COPY logout.php /var/www/html/
COPY getConfig.php /var/www/html/
COPY setUserPassword.php /var/www/html/

# data directories
RUN mkdir -p /var/www/html/verona-modules
RUN mkdir -p /var/www/html/config
RUN mkdir -p /var/www/html/vo_tmp

# set file rights
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

COPY docker/entrypoint.sh /root/entrypoint.sh
RUN chmod 777 /root/entrypoint.sh
ENTRYPOINT ["/root/entrypoint.sh"]
