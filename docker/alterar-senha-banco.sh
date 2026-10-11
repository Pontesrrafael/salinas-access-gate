#!/usr/bin/env bash
# =====================================================================
# Salinas Access Gate — troca de senha do banco (Docker)
# Uso: sudo bash /opt/salinas-access-gate/docker/alterar-senha-banco.sh [app|root|bi]
#   app  (padrão) usuário da aplicação (DB_USERNAME)  → atualiza db.env e app.env e reinicia o app
#   root          root do MariaDB                    → atualiza db.env
#   bi            usuário de consulta do BI (sag_bi)   → atualiza bi.env (Power BI/Metabase precisam da nova senha)
# A senha é digitada sem eco (Enter = gerar). Sem terminal: SAG_NOVA_SENHA=... (mesmas regras do instalador).
# Documento: MD-TI-SIS-CBA-005 / MD-TI-SIS-CBA-008
# =====================================================================
set -euo pipefail
DESTINO="${SAG_DESTINO:-/opt/salinas-access-gate}"
ALVO="${1:-app}"
aviso() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
falha() { printf '\033[1;31m[ERRO] %s\033[0m\n' "$*"; exit 1; }
[ "$(id -u)" -eq 0 ] || falha "Execute com sudo."
cd "$DESTINO" || falha "Pasta $DESTINO não encontrada."
[ -f docker/db.env ] && [ -f docker/app.env ] || falha "docker/db.env ou app.env ausente: instale primeiro."
[[ "$ALVO" =~ ^(app|root|bi)$ ]] || falha "Use: app, root ou bi."

senha_valida() {
  local s="$1"
  [ "${#s}" -ge 12 ] && [ "${#s}" -le 64 ] || { echo "use de 12 a 64 caracteres"; return 1; }
  [[ "$s" =~ ^[A-Za-z0-9@%+=:,._!*~^()-]+$ ]] || { echo "use só letras, números e @ % + = : , . _ ! * ~ ^ ( ) -"; return 1; }
  [[ "$s" =~ [A-Z] ]] && [[ "$s" =~ [a-z] ]] && [[ "$s" =~ [0-9] ]] || { echo "inclua maiúscula, minúscula e número"; return 1; }
}
valor() { grep -E "^$1=" "$2" | head -1 | cut -d= -f2-; }
trocar_linha() { # arquivo chave valor (sem expor o valor em argumentos de outros processos além do sed local)
  local tmp; tmp="$(mktemp "$1.XXXX")"
  awk -v k="$2" -v v="$3" 'BEGIN{FS=OFS="="} $1==k{print k "=" v; next} {print}' "$1" > "$tmp"
  chmod 600 "$tmp"; mv "$tmp" "$1"
}

NOVA="${SAG_NOVA_SENHA:-}"
if [ -z "$NOVA" ] && [ -t 0 ]; then
  while true; do
    read -r -s -p "Nova senha ($ALVO) (Enter = gerar automaticamente): " s1; echo
    [ -z "$s1" ] && break
    if ! m="$(senha_valida "$s1")"; then aviso "Senha recusada: $m."; continue; fi
    read -r -s -p "Confirme: " s2; echo
    [ "$s1" = "$s2" ] || { aviso "As senhas não conferem."; continue; }
    NOVA="$s1"; break
  done
fi
GERADA=0
if [ -z "$NOVA" ]; then NOVA="$(openssl rand -hex 24)"; GERADA=1; else m="$(senha_valida "$NOVA")" || falha "Senha recusada: $m"; fi

case "$ALVO" in
  app)  USUARIO="$(valor MARIADB_USER docker/db.env)"; HOSTS="'%'" ;;
  root) USUARIO="root"; HOSTS="'localhost'" ;;
  bi)   [ -f docker/bi.env ] || falha "docker/bi.env ausente."; USUARIO="$(valor SAG_BI_USUARIO docker/bi.env)"; USUARIO="${USUARIO:-sag_bi}"; HOSTS="'%'" ;;
esac
[[ "$USUARIO" =~ ^[a-z][a-z0-9_]{1,31}$ ]] || falha "Usuário inválido em db.env/bi.env: $USUARIO"

echo "Alterando a senha de '$USUARIO' no MariaDB..."
SQL="ALTER USER '$USUARIO'@$HOSTS IDENTIFIED BY '$NOVA';"
[ "$ALVO" = root ] && SQL="$SQL ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$NOVA';"
printf '%s\n' "$SQL" | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' \
  || falha "O MariaDB recusou a troca. Nada foi alterado nos arquivos."

case "$ALVO" in
  app)  trocar_linha docker/db.env MARIADB_PASSWORD "$NOVA"; trocar_linha docker/app.env DB_PASSWORD "$NOVA"
        docker compose up -d --force-recreate app >/dev/null && echo "Aplicação reiniciada com a nova senha." ;;
  root) trocar_linha docker/db.env MARIADB_ROOT_PASSWORD "$NOVA"
        docker compose up -d --force-recreate db >/dev/null && echo "Banco reiniciado (a senha do root vale também para backup e instalador)." ;;
  bi)   trocar_linha docker/bi.env SAG_BI_SENHA "$NOVA"; echo "Atualize a senha nas conexões do Power BI/Metabase." ;;
esac
unset NOVA SQL
[ "$GERADA" = 1 ] && echo "Senha gerada automaticamente: consulte o arquivo em $DESTINO/docker/ e guarde no cofre."
echo "Concluído em $(date '+%d/%m/%Y %H:%M') (Horário de Mato Grosso – UTC-4)."
