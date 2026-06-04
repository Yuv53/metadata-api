FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libimage-exiftool-perl \
    && rm -rf /var/lib/apt/lists/*

COPY index.php /var/www/html/index.php

RUN echo "upload_max_filesize=500M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN a2enmod rewrite

EXPOSE 80

CMD ["apache2-foreground"]
