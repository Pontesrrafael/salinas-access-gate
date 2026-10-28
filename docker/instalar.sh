#!/usr/bin/env bash
# =====================================================================
# Salinas Access Gate — instalação/atualização em Docker (Ubuntu Server)
# Uso (no servidor):  sudo SAG_PORTA=222 bash docker/instalar.sh [PASTA_ORIGEM]
#   - Instala Docker (se faltar), copia o código para /opt/salinas-access-gate,
#     gera os segredos na 1ª vez, sobe aplicação + MariaDB na porta SAG_PORTA,
#     cria o 1º admin e agenda tarefas diárias (expiração + backup).
#   - Reexecutar = atualizar: mantém segredos, banco e arquivos (volumes).
# Documento: MD-TI-SIS-CBA-005 (docs/05-DEPLOY-DOCKER-UBUNTU.md)
# =====================================================================
set -euo pipefail
DESTINO=/opt/salinas-access-gate
PORTA="${SAG_PORTA:-222}"
ORIGEM="${1:-$(cd "$(dirname "$0")/.." && pwd)}"

etapa() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
aviso() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
falha() { printf '\033[1;31m[ERRO] %s\033[0m\n' "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || falha "Execute com sudo."
[ -f "$ORIGEM/docker-compose.yml" ] || falha "docker-compose.yml não encontrado em $ORIGEM"
[[ "$PORTA" =~ ^[0-9]+$ ]] && [ "$PORTA" -ge 1 ] && [ "$PORTA" -le 65535 ] || falha "Porta inválida: $PORTA"

etapa "Sistema"
. /etc/os-release 2>/dev/null || true
echo "${PRETTY_NAME:-Linux} · $(uname -m) · $(hostname)"

etapa "Docker"
if ! command -v docker >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq docker.io docker-compose-v2 || { aviso "Pacotes do Ubuntu indisponíveis; usando o instalador oficial (get.docker.com)"; curl -fsSL https://get.docker.com | sh; }
fi
systemctl enable --now docker >/dev/null 2>&1 || true
if ! docker compose version >/dev/null 2>&1; then
  apt-get install -y -qq docker-compose-v2 || apt-get install -y -qq docker-compose-plugin || falha "Não foi possível instalar o plugin docker compose."
fi
docker --version; docker compose version

etapa "Porta $PORTA"
if ss -ltnpH "sport = :$PORTA" 2>/dev/null | grep -q .; then
  if ss -ltnpH "sport = :$PORTA" | grep -q docker-proxy && [ -f "$DESTINO/docker/app.env" ]; then
    echo "Porta $PORTA já é do Salinas Access Gate (atualização)."
  else
    ss -ltnpH "sport = :$PORTA"
    falha "A porta $PORTA já está em uso por outro serviço (acima). Libere a porta ou use outra: sudo SAG_PORTA=8222 bash docker/instalar.sh"
  fi
else
  echo "Porta $PORTA livre."
fi

etapa "Código em $DESTINO"
mkdir -p "$DESTINO"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete --exclude 'docker/app.env' --exclude 'docker/db.env' --exclude '.env' --exclude 'storage/' "$ORIGEM"/ "$DESTINO"/
else
  (cd "$ORIGEM" && tar --exclude='./docker/app.env' --exclude='./docker/db.env' --exclude='./.env' --exclude='./storage' -cf - .) | (cd "$DESTINO" && tar -xf -)
fi
find "$DESTINO/docker" -name '*.sh' -exec sed -i 's/\r$//' {} + -exec chmod 750 {} +
cd "$DESTINO"

etapa "Segredos"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [ ! -f docker/app.env ]; then
  command -v openssl >/dev/null || apt-get install -y -qq openssl
  SENHA_DB="$(openssl rand -hex 24)"; SENHA_ROOT="$(openssl rand -hex 24)"
  cat > docker/db.env <<ENV
MARIADB_ROOT_PASSWORD=$SENHA_ROOT
MARIADB_DATABASE=salinas_access_gate
MARIADB_USER=sag_app
MARIADB_PASSWORD=$SENHA_DB
ENV
  cat > docker/app.env <<ENV
# Gerado em $(date '+%d/%m/%Y %H:%M') por docker/instalar.sh — GUARDE UMA CÓPIA EM COFRE (chaves do CPF)
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${IP:-localhost}:${PORTA}
APP_TIMEZONE=America/Cuiaba
DB_TIME_ZONE=-04:00
DB_HOST=db
DB_PORT=3306
DB_DATABASE=salinas_access_gate
DB_USERNAME=sag_app
DB_PASSWORD=$SENHA_DB
SESSION_NAME=sag_sessao
SESSION_IDLE_MINUTES=120
# HTTP sem TLS na rede interna; mude para true quando houver HTTPS (proxy reverso)
SESSION_SECURE_COOKIE=false
CPF_ENCRYPTION_KEY=$(openssl rand -base64 32)
CPF_INDEX_KEY=$(openssl rand -base64 32)
LDAP_ENABLED=false
ENV
  chmod 600 docker/app.env docker/db.env
  echo "Segredos gerados em $DESTINO/docker/app.env e db.env (somente root)."
  NOVO=1
else
  echo "Segredos existentes mantidos (atualização)."
  NOVO=0
fi
printf 'SAG_PORTA=%s\n' "$PORTA" > .env
chmod 600 .env

etapa "Imagens base"
# Docker Hub limita downloads anônimos (erro 429). Se falhar, usa os mirrors oficiais (Google e AWS) e re-etiqueta.
puxar() {
  local img="$1" nome="${1%%:*}" tag="${1##*:}"
  docker pull -q "$img" >/dev/null 2>&1 && { echo "ok  $img"; return 0; }
  for m in "mirror.gcr.io/library/$nome:$tag" "public.ecr.aws/docker/library/$nome:$tag"; do
    if docker pull -q "$m" >/dev/null 2>&1; then docker tag "$m" "$img"; echo "ok  $img (via ${m%%/*})"; return 0; fi
  done
  docker image inspect "$img" >/dev/null 2>&1 && { aviso "Sem acesso aos registros; usando $img já baixada."; return 0; }
  falha "Não foi possível baixar $img (Docker Hub e mirrors). Verifique a internet do servidor."
}
puxar php:8.5-apache-trixie
puxar mariadb:11.8

etapa "Build e inicialização (pode levar alguns minutos na 1ª vez)"
docker compose build || falha "Build falhou (veja as mensagens acima)."
docker compose up -d --remove-orphans

etapa "Aguardando a aplicação ficar saudável"
for i in $(seq 1 90); do
  ST="$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q app)" 2>/dev/null || echo '?')"
  [ "$ST" = healthy ] && break
  sleep 2
done
[ "$ST" = healthy ] || { docker compose logs --tail=60 app; falha "A aplicação não ficou saudável (status: $ST)."; }
echo "OK — $(curl -fsS "http://127.0.0.1:${PORTA}/saude" 2>/dev/null || echo 'saude sem resposta local')"

etapa "Administrador"
ADMINS="$(docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM usuarios WHERE papel=\"admin\" AND ativo=1"' 2>/dev/null || echo 0)"
if [ "${ADMINS:-0}" = "0" ]; then
  EMAIL_ADMIN="${SAG_ADMIN_EMAIL:-ti@salinasgold.com.br}"; NOME_ADMIN="${SAG_ADMIN_NOME:-TI Salinas}"
  if [ -t 0 ] && [ -z "${SAG_ADMIN_EMAIL:-}" ]; then
    read -r -p "E-mail do administrador [$EMAIL_ADMIN]: " r; EMAIL_ADMIN="${r:-$EMAIL_ADMIN}"
    read -r -p "Nome do administrador [$NOME_ADMIN]: " r; NOME_ADMIN="${r:-$NOME_ADMIN}"
    echo "Na pergunta da senha, aperte Enter para gerar uma senha TEMPORÁRIA (troca obrigatória no 1º acesso)."
    docker compose exec app php bin/console.php criar-admin --email="$EMAIL_ADMIN" --nome="$NOME_ADMIN"
  else
    docker compose exec -T app php bin/console.php criar-admin --email="$EMAIL_ADMIN" --nome="$NOME_ADMIN" < /dev/null
  fi
else
  echo "Já existe administrador ativo ($ADMINS)."
fi

etapa "Tarefas diárias e backup"
cat > /etc/cron.d/salinas-access-gate <<CRON
# Salinas Access Gate — Horário de Mato Grosso – UTC-4 (container); horário do cron = fuso do servidor
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
5 0 * * *  root cd $DESTINO && docker compose exec -T app php bin/console.php expirar-solicitacoes >> /var/log/salinas-access-gate.log 2>&1
20 0 * * * root cd $DESTINO && docker compose exec -T app php bin/console.php aplicar-retencao >> /var/log/salinas-access-gate.log 2>&1
30 0 * * * root cd $DESTINO && docker compose exec -T app php bin/console.php fechar-chamados >> /var/log/salinas-access-gate.log 2>&1
*/5 * * * * root cd $DESTINO && docker compose exec -T app php bin/console.php enviar-emails >> /var/log/salinas-access-gate.log 2>&1
30 1 * * * root $DESTINO/docker/backup.sh >> /var/log/salinas-access-gate.log 2>&1
CRON
chmod 644 /etc/cron.d/salinas-access-gate
echo "Agendado: expiração de solicitações 00:05, retenção LGPD 00:20 e backup 01:30 (retenção 14 dias em /var/backups/salinas-access-gate)."

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "$PORTA"/tcp comment 'Salinas Access Gate' >/dev/null && echo "UFW: porta $PORTA/tcp liberada."
fi

etapa "Concluído"
echo "Acesse:      http://${IP:-IP_DO_SERVIDOR}:${PORTA}"
echo "Saúde:       http://${IP:-IP_DO_SERVIDOR}:${PORTA}/saude"
echo "Pasta:       $DESTINO   (logs: docker compose logs -f app)"
[ "$NOVO" = 1 ] && aviso "Copie $DESTINO/docker/app.env para o cofre de senhas: sem as chaves CPF_* os CPFs ficam ilegíveis."
if [ ! -s /etc/salinas-access-gate/backup.key ]; then
  mkdir -p /etc/salinas-access-gate && (umask 077; openssl rand -base64 48 > /etc/salinas-access-gate/backup.key)
  aviso "Chave dos backups criada em /etc/salinas-access-gate/backup.key — copie-a para o cofre de senhas."
fi
exit 0
