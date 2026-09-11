FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
