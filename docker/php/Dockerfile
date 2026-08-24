# --- Stage dédié à la récupération de wkhtmltopdf -------------------------
# wkhtmltopdf n'est plus packagé dans les dépôts Debian récents (trixie n'a
# que libssl3, alors que le binaire officiel a besoin de libssl.so.1.1). On
# le récupère donc via un stage basé sur bullseye (qui a encore libssl1.1
# nativement) et on copie ensuite le binaire + ses libs dans l'image finale
# — même logique que sur l'hébergement IONOS : pas de paquet système
# disponible sans root, juste des fichiers à déposer et rendre exécutables.
FROM debian:bullseye-slim AS wkhtmltopdf

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl ca-certificates libssl1.1 \
    && curl -fsSL -o /tmp/wkhtmltox.deb \
        https://github.com/wkhtmltopdf/wkhtmltopdf/releases/download/0.12.5/wkhtmltox_0.12.5-1.buster_amd64.deb \
    && dpkg-deb -x /tmp/wkhtmltox.deb /tmp/wkhtmltox \
    && rm -rf /var/lib/apt/lists/* /tmp/wkhtmltox.deb

FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
        git \
        fontconfig \
        libxrender1 \
        libxext6 \
        libjpeg62-turbo \
        libx11-6 \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl opcache zip gd bcmath \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Binaire + libs wkhtmltopdf (voir stage ci-dessus) : libssl1.1/libcrypto1.1
# sont copiées à côté plutôt qu'installées via apt, puisque trixie ne les
# fournit plus du tout.
COPY --from=wkhtmltopdf /tmp/wkhtmltox/usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf
COPY --from=wkhtmltopdf /tmp/wkhtmltox/usr/local/bin/wkhtmltoimage /usr/bin/wkhtmltoimage
COPY --from=wkhtmltopdf /usr/lib/x86_64-linux-gnu/libssl.so.1.1 /usr/lib/x86_64-linux-gnu/libssl.so.1.1
COPY --from=wkhtmltopdf /usr/lib/x86_64-linux-gnu/libcrypto.so.1.1 /usr/lib/x86_64-linux-gnu/libcrypto.so.1.1
RUN chmod +x /usr/bin/wkhtmltopdf /usr/bin/wkhtmltoimage && ldconfig

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e '/<Directory \$\{APACHE_DOCUMENT_ROOT\}>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
