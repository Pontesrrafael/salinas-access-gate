## O que muda
<!-- Resumo objetivo da alteração e do motivo -->

## Como foi testado
- [ ] `php bin/console.php verificar` sem erros
- [ ] Fluxo afetado testado manualmente (portaria / solicitações / indicadores...)
- [ ] CI verde

## Impacto
- [ ] Nova migração em `database/migrations` (aplicada automaticamente no início do container)
- [ ] Nova variável de ambiente (documentada em `.env.example` e no runbook)
- [ ] Afeta dados pessoais (CPF, fotos) — avaliar LGPD

## Checklist de segurança
- [ ] Nenhum segredo, senha, token ou `.env` no commit
- [ ] Entradas validadas no servidor e saídas escapadas com `e()`
