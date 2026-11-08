FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev libjpeg62-turbo-dev libpng-dev libwebp-dev msmtp-mta \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring curl gd exif \
    && rm -rf /var/lib/apt/lists/*

COPY msmtprc /etc/msmtprc
RUN chmod 644 /etc/msmtprc

WORKDIR /app

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
