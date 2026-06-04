FROM php:8.2-apache
RUN apt-get update && apt-get install -y \
    libimage-exiftool-perl \
    && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN echo "upload_max_filesize=500M\npost_max_size=500M\nmemory_limit=512M" > /usr/local/etc/php/conf.d/uploads.ini
EXPOSE 80
