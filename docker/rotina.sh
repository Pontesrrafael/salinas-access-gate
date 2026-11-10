#!/usr/bin/env bash
# Executa uma rotina agendada do Salinas Access Gate e registra o resultado para o painel do Admin/TI.
# Uso: rotina.sh <pasta-da-instalacao> <nome> <comando...>
#   nome: backup | aplicar-retencao | fechar-chamados | expirar-solicitacoes | enviar-emails
set -u
DESTINO="$1"; NOME="$2"; shift 2
cd "$DESTINO" || exit 1
"$@"
RC=$?
if [ "$RC" -ne 0 ]; then
  echo "$(date '+%d/%m/%Y %H:%M') FALHA na rotina $NOME (código $RC)"
  docker compose exec -T app php bin/console.php rotina-registrar "$NOME" falha || true
elif [ "$NOME" != "enviar-emails" ] || [ "$(date +%M)" = "00" ]; then
  # A fila de e-mails roda a cada 5 min: o sucesso é registrado só uma vez por hora.
  docker compose exec -T app php bin/console.php rotina-registrar "$NOME" ok >/dev/null || true
fi
exit "$RC"
