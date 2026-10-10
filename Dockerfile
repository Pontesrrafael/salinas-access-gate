# Salinas Access Gate — imagem da aplicação (PHP 8.3 + Apache)
# Build: docker compose build   ·   Doc: docs/05-DEPLOY-DOCKER-UBUNTU.md
FROM php:8.3-apache-bookworm

ENV TZ=America/Cuiaba \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

# Extensões: pdo_mysql, ldap (AD opcional), opcache. sodium, mbstring, fileinfo e curl já vêm na imagem oficial.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends tzdata libldap2-dev; \
    docker-php-ext-configure ldap --with-libdir="lib/$(uname -m)-linux-gnu"; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql ldap opcache; \
    rm -rf /var/lib/apt/lists/*; \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod rewrite headers; \
    php -m | grep -qi sodium && php -m | grep -qi mbstring && php -m | grep -qi fileinfo

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-salinas.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/seguranca.conf /etc/apache2/conf-available/zz-seguranca.conf
RUN a2enconf zz-seguranca

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
RUN set -eux; \
    sed -i 's/\r$//' docker/*.sh; chmod +x docker/*.sh; \
    mkdir -p storage/uploads storage/logs storage/sessions storage/cache storage/importacao; \
    chown -R www-data:www-data storage; \
    rm -f .env

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/saude") === false ? 1 : 0);'

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
