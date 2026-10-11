#!/usr/bin/env bash
# Backup do Salinas Access Gate (Docker): banco + fotos + certificados + segredos.
# Uso: sudo /opt/salinas-access-gate/docker/backup.sh
# Com docker/backup.chave (criada pelo instalador), TODOS os arquivos saem cifrados (AES-256, PBKDF2):
# uma cópia do backup no NAS não expõe CPF nem as chaves. Guarde backup.chave no cofre (sem ela não há restauração).
set -euo pipefail
DESTINO="${SAG_DESTINO:-/opt/salinas-access-gate}"
DIR="${SAG_BACKUP_DIR:-/var/backups/salinas-access-gate}"
DIAS="${SAG_BACKUP_DIAS:-14}"
umask 077
mkdir -p "$DIR"; chmod 700 "$DIR"
cd "$DESTINO"
D="$(date +%F-%H%M)"
CHAVE="$DESTINO/docker/backup.chave"
if [ -f "$CHAVE" ]; then
  EXT=".enc"; cifrar() { openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$CHAVE"; }
else
  EXT=""; cifrar() { cat; }
  echo "[!] docker/backup.chave ausente: backup SEM criptografia (rode o instalador para criar a chave)." >&2
fi
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' | gzip | cifrar > "$DIR/banco-$D.sql.gz$EXT"
docker compose exec -T app tar -czf - -C /var/www/html/storage uploads certs | cifrar > "$DIR/fotos-$D.tar.gz$EXT"
cifrar < docker/app.env > "$DIR/app.env-$D$EXT"
[ -f docker/bi.env ] && cifrar < docker/bi.env > "$DIR/bi.env-$D$EXT"
find "$DIR" -type f -mtime +"$DIAS" -delete
echo "$(date '+%d/%m/%Y %H:%M') backup ok${EXT:+ (cifrado)}: $(du -ch "$DIR"/*-"$D"* | tail -1 | cut -f1) em $DIR"
