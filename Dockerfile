# Salinas Access Gate — imagem da aplicação (PHP 8.5 + Apache, Debian 13 trixie)
# Build: docker compose build   ·   Doc: docs/05-DEPLOY-DOCKER-UBUNTU.md
# Mantenha esta versão igual à de docker/instalar.sh (função puxar). O Dependabot propõe atualizações.
FROM php:8.5-apache-trixie

ENV TZ=America/Cuiaba \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

# Extensões: pdo_mysql e ldap (AD opcional). sodium, mbstring, fileinfo, curl e (no PHP 8.5) OPcache já vêm na imagem oficial.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends tzdata libldap2-dev libheif-examples libheif-plugin-libde265; \
    (command -v heif-dec || command -v heif-convert); \
    docker-php-ext-configure ldap --with-libdir="lib/$(uname -m)-linux-gnu"; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql ldap; \
    php -m | grep -qi "Zend OPcache" || docker-php-ext-install opcache; \
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
# Código pertence ao root e é só leitura para o Apache (www-data): uma falha na aplicação
# não consegue alterar o próprio código. Só storage/ é gravável.
COPY . /var/www/html
RUN set -eux; \
    sed -i 's/\r$//' docker/*.sh; chmod +x docker/*.sh; \
    rm -f .env docker/*.env; \
    chown -R root:root /var/www/html; \
    find /var/www/html -path /var/www/html/storage -prune -o -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +; \
    chmod 755 docker/*.sh; \
    mkdir -p storage/uploads storage/logs storage/sessions storage/cache storage/importacao storage/certs; \
    chown -R www-data:www-data storage; chmod -R u+rwX,g+rX,o-rwx storage

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/saude") === false ? 1 : 0);'

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
