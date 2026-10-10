# Segurança

## Reportar uma vulnerabilidade
Não abra *issue* pública. Envie os detalhes para a TI da Salinas Gold (ti@salinasgold.com.br), com passos para reproduzir e impacto.
Resposta inicial em até 2 dias úteis.

## Segredos
- `.env`, `docker/app.env` e `docker/db.env` **nunca** são versionados (ver `.gitignore` e `.dockerignore`).
- As chaves `CPF_ENCRYPTION_KEY` e `CPF_INDEX_KEY` ficam no cofre de senhas da TI. Sem elas os CPFs cifrados ficam ilegíveis.
- Se um segredo for exposto: gere novo valor, atualize o ambiente e registre o incidente. As chaves do CPF **não** podem ser trocadas sem migração dos dados.

## Controles do sistema
CSRF em todo POST, CSP sem `unsafe-inline`, sessões HttpOnly/SameSite, bcrypt, limitação de tentativas, CPF cifrado (libsodium) com índice cego,
tabelas de auditoria imutáveis (triggers), uploads validados e servidos só com autenticação.
