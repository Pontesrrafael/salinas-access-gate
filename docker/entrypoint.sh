#!/bin/sh
# Inicialização do container: aguarda o banco, aplica migrações e sobe o Apache.
set -e
cd /var/www/html
mkdir -p storage/uploads storage/logs storage/sessions storage/cache storage/importacao storage/certs

echo "[sag] aguardando o banco ${DB_HOST:-db}:${DB_PORT:-3306}..."
i=0
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then echo "[sag] banco indisponível após 120 s"; exit 1; fi
  sleep 2
done

if [ -z "${CPF_ENCRYPTION_KEY}" ] || [ -z "${CPF_INDEX_KEY}" ]; then
  echo "[sag] ERRO: CPF_ENCRYPTION_KEY/CPF_INDEX_KEY ausentes em docker/app.env"; exit 1
fi

echo "[sag] aplicando migrações..."
php bin/console.php migrar
chown -R www-data:www-data storage

exec docker-php-entrypoint "$@"
