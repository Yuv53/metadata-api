FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    php8.1 \
    php8.1-cli \
    libimage-exiftool-perl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY index.php /app/index.php

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
