#!/usr/bin/env bash
# Backup do Salinas Access Gate (Docker): banco + fotos + certificados + segredos. Uso: sudo /opt/salinas-access-gate/docker/backup.sh
set -euo pipefail
DESTINO="${SAG_DESTINO:-/opt/salinas-access-gate}"
DIR="${SAG_BACKUP_DIR:-/var/backups/salinas-access-gate}"
DIAS="${SAG_BACKUP_DIAS:-14}"
umask 077
mkdir -p "$DIR"
cd "$DESTINO"
D="$(date +%F-%H%M)"
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' | gzip > "$DIR/banco-$D.sql.gz"
docker compose exec -T app tar -czf - -C /var/www/html/storage uploads certs > "$DIR/fotos-$D.tar.gz"
cp docker/app.env "$DIR/app.env-$D"
[ -f docker/bi.env ] && cp docker/bi.env "$DIR/bi.env-$D"
find "$DIR" -type f -mtime +"$DIAS" -delete
echo "$(date '+%d/%m/%Y %H:%M') backup ok: $(du -ch "$DIR"/*-"$D"* | tail -1 | cut -f1) em $DIR"
