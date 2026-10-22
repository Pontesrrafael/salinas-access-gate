# Tutoriais do Access Gate

## Uso padrão

O menu **Tutoriais** é fixo para usuários autenticados. O catálogo mostra somente os módulos permitidos ao perfil. **Como usar**, no topo das telas, abre os procedimentos mesmo depois da dispensa ou conclusão do tour. Na central, a ajuda de outro módulo oferece navegação até a tela e início do tour.

O guia cobre Painel, Portaria, Solicitações, SSMA, Pessoas, Veículos, Presença, Indicadores, Relatórios, Registro de acesso, Auditoria, Infleet, Locais, Usuários e Configurações. No modo TV a sugestão e o botão contextual ficam ocultos; ao sair desse modo, a ajuda volta a ficar disponível.

O tour apenas destaca elementos. Não digita, clica em controles da operação, salva, envia arquivos ou muda permissões. Não substitui treinamento formal, autorização de visita ou análise SSMA.

## Manutenção

- `app/Services/Tutoriais.php`: conteúdo, versão, rota, permissão, procedimentos, resultados, recuperação e etapas estáveis.
- `app/Controllers/TutoriaisController.php`: catálogo com login obrigatório.
- `app/Views/tutoriais/`: catálogo e estrutura dos diálogos compartilhados.
- `public/assets/js/tutoriais.js`: ajuda, apresentação dos passos e armazenamento.
- `public/assets/css/tutoriais.css`: adaptações aos tokens de cor, fontes, tema e tamanho da aplicação.

Ao mudar uma tela, confira os procedimentos e seus seletores `data-tour`. Alvos opcionais ausentes usam `skip`. As etapas de introdução e orientação geral usam apresentação central. Alvos que desaparecem durante o tour provocam recálculo do contador. A espera usa observação do DOM com prazo de segurança. Não há preparação automática de abas ou formulários: os tutoriais atuais não dependem de abas nem abrem diálogos da operação.

Uma mudança relevante de conteúdo deve incrementar `version` no registro do módulo. O responsável técnico registrado é Tecnologia da Informação; a homologação operacional do texto cabe às áreas responsáveis.

## Progresso e privacidade

Armazenamento escolhido: **localStorage por usuário, módulo e versão**, somente neste navegador. A preferência de sugestão fica em chave separada. Os estados são não iniciado, em andamento, concluído e dispensado. É possível retomar a etapa estável ou reiniciar o tour. O catálogo mostra o estado de cada módulo.

Não há sincronização entre equipamentos, migração ou alteração de banco. Não armazenamos valores digitados, nome, CPF, registros de acesso ou telemetria de utilização. Se o armazenamento estiver bloqueado, a ajuda manual funciona e a sugestão automática fica desabilitada para evitar repetição. Apagar os dados do navegador remove progresso e preferências locais. O identificador do usuário na chave serve para separar as contas no mesmo equipamento; não concede acesso a módulos.

## Verificações

Execução local em 22/10/2026, sem dados de produção:

- `php tests/tutoriais.php`: quatro perfis, visitante, matriz de acesso, conteúdo e subrotas.
- `node --check public/assets/js/tutoriais.js`: sintaxe do JavaScript.
- `tests/tutoriais.browser.cjs`: Chromium isolado com fixture PHP, sem banco. Abrir/fechar, Escape e retorno do foco, Anterior/Próximo/Concluir, reinício, dispensa, alvo removido, contador, navegação pelo catálogo, busca, preferência separada, usuários distintos e viewport de 375 × 667. Nenhum POST.
- Regressão: `php tests/infleet.php` e `node --test tests/infleet_maps.test.cjs`.

Para repetir o teste de interface, use um terminal separado:

```text
php -S 127.0.0.1:8238 -t public tests/fixtures/tutorial-router.php
node tests/tutoriais.browser.cjs
```

O teste de navegador requer Playwright e Chrome já disponíveis. `PLAYWRIGHT_MODULE` permite indicar o módulo instalado e `TUTORIAL_TEST_URL` permite mudar a porta. A fixture é exclusiva de testes: não configure o servidor publicado para usá-la como roteador.

## Pendências

- Homologar os textos com Portaria, SSMA e Segurança Patrimonial.
- Publicar a versão no ambiente instalado e conferir cada módulo com sessões reais dos perfis, estados vazios/com dados, tema escuro e zoom. A fixture verifica o componente compartilhado, não as regras do banco em produção.
- Validar o conteúdo e a ajuda dos percursos Infleet após homologação da integração. Os tutoriais não habilitam essa integração.

## Pontos de melhoria

- Vincular revisão de tutoriais à revisão de interface.
- Avaliar progresso sincronizado somente se houver necessidade de continuidade entre equipamentos.
- Acrescentar procedimentos específicos para edição e documentação conforme as dúvidas das equipes.
