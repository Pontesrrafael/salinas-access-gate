# Como contribuir

1. Crie um branch a partir de `main`: `feature/<assunto>` ou `fix/<assunto>`.
2. Mudanças de banco entram como **nova** migração em `database/migrations/NNN_descricao.sql` (nunca edite uma já aplicada).
3. Rode localmente (`bin\INICIAR.bat` no Windows ou `docker compose up -d`) e `php bin/console.php verificar`.
4. Abra um Pull Request preenchendo o modelo; o CI precisa estar verde e a revisão da TI (CODEOWNERS) é obrigatória.
5. Commits e textos em português; código segue o padrão existente (PSR-12, `declare(strict_types=1)`, saída sempre por `e()`).

Implantação: `docs/05-DEPLOY-DOCKER-UBUNTU.md` (MD-TI-SIS-CBA-005).
