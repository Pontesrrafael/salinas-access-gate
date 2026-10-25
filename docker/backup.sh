#!/usr/bin/env bash
# Backup do Salinas Access Gate (Docker): banco + fotos, CIFRADOS (AES-256, openssl/PBKDF2).
# Uso: sudo /opt/salinas-access-gate/docker/backup.sh
#
# ISO 27001 A.8.13/A.8.24 e LGPD art. 46:
#   - o backup NÃO leva o app.env (chaves do CPF): ele fica só no cofre de senhas da TI;
#   - a chave do backup fica em $SAG_BACKUP_CHAVE (fora da pasta de backup) e também deve ir para o cofre;
#   - SAG_BACKUP_REMOTO (opcional, destino do rsync, ex.: backup@nas:/salinas/) mantém uma cópia fora do servidor.
set -euo pipefail
DESTINO="${SAG_DESTINO:-/opt/salinas-access-gate}"
DIR="${SAG_BACKUP_DIR:-/var/backups/salinas-access-gate}"
DIAS="${SAG_BACKUP_DIAS:-14}"
CHAVE="${SAG_BACKUP_CHAVE:-/etc/salinas-access-gate/backup.key}"
REMOTO="${SAG_BACKUP_REMOTO:-}"
umask 077
command -v openssl >/dev/null || { echo "ERRO: openssl não instalado (apt-get install openssl)" >&2; exit 1; }
if [ ! -s "$CHAVE" ]; then
  mkdir -p "$(dirname "$CHAVE")"
  openssl rand -base64 48 > "$CHAVE"
  chmod 600 "$CHAVE"
  echo "AVISO: chave de backup criada em $CHAVE — copie-a para o cofre de senhas; sem ela os backups não abrem." >&2
fi
mkdir -p "$DIR"
cd "$DESTINO"
D="$(date +%F-%H%M)"
cifrar() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$CHAVE"; }
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' \
  | gzip | cifrar > "$DIR/banco-$D.sql.gz.enc"
docker compose exec -T app tar -czf - -C /var/www/html/storage uploads | cifrar > "$DIR/fotos-$D.tar.gz.enc"
# Também remove, pelo prazo, as cópias em texto puro e os app.env deixados por versões anteriores
find "$DIR" -type f -mtime +"$DIAS" -delete
if [ -n "$REMOTO" ]; then
  rsync -a --delete "$DIR"/ "$REMOTO" && echo "cópia externa ok: $REMOTO"
fi
echo "$(date '+%d/%m/%Y %H:%M') backup cifrado ok: $(du -ch "$DIR"/*-"$D"* | tail -1 | cut -f1) em $DIR"
