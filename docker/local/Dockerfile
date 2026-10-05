FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev msmtp-mta \
    && docker-php-ext-install pdo_mysql mbstring curl \
    && rm -rf /var/lib/apt/lists/*

COPY msmtprc /etc/msmtprc
RUN chmod 644 /etc/msmtprc

WORKDIR /app

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
