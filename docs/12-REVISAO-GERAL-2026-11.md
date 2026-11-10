# Revisão geral — pontos de melhoria (10/11/2026)

Base: branch `feat/chamados-revisao`, commit `0224bcb`. Revisores: Claude (segurança, desempenho, operação, lacunas de produto)
e Gemini 4 Argon low (telas, documentação, traduções). Cada item do Gemini marcado "conferido" foi verificado no código pelo gerente.
Nada foi alterado no código nesta etapa.

Legenda de esforço: P = até meio dia · M = 1 a 2 dias · G = mais de 2 dias.

## Prioridade alta

| # | Ponto | Onde | Melhoria | Esforço |
|---|---|---|---|---|
| A1 | Produção roda um branch de trabalho; `main` está 55 commits atrás e há 6 PRs empilhados abertos (#8 a #13) | GitHub | Fazer o merge em ordem (#8→#13), fechar #5 a #7 e passar a publicar a partir de `main` com tag de versão | P |
| A2 | Migração 014 e as consultas da API nunca rodaram em MariaDB (testes usam banco simulado) | `tests/api.php`, CI | Rodar `tests/api.php` no CI e acrescentar um teste de fumaça da API no job que já sobe o Docker | M |
| A3 | SLA não muda com a urgência/prioridade (conferido) | `app/Services/Chamados.php:68` | Tabela de prazos por prioridade em cada categoria (ex.: urgente = 24 h, normal = 3 dias) | M |
| A4 | Formulário dinâmico sem campos condicionais (conferido) | `app/Services/FormularioDinamico.php` | "Mostrar/exigir se outro campo = valor" (ex.: desenho técnico, justificativa de urgência) | M |
| A5 | Solicitações de acesso e SSMA não avisam ninguém por e-mail (conferido: nenhum uso de `Notificacoes` nesses controladores) | `SolicitacoesController`, `SsmaController`, `PublicoController` | Avisar o autorizador da área, o SSMA e o solicitante a cada mudança de status | M |
| A6 | Chamados sem indicadores, relatório e exportação (conferido) | `Indicadores.php`, `RelatoriosController.php`, `ChamadosController.php` | Painel de volume, backlog e cumprimento de SLA por área; exportar a fila em CSV | M |
| A7 | Verificação de "há administrador ativo" ignora os perfis múltiplos (conferido) | `bin/console.php:141`, `bin/instalar-local.ps1:91` | Consultar `usuario_perfis`, como o `docker/instalar.sh` já faz | P |
| A8 | Nenhum aviso quando backup, retenção ou envio de e-mails falham no cron; o log `/var/log/salinas-access-gate.log` cresce sem rotação | `docker/instalar.sh:147-157` | Arquivo de logrotate e aviso por e-mail/painel quando uma rotina noturna falhar; teste de restauração trimestral | P |

## Prioridade média

| # | Ponto | Onde | Melhoria | Esforço |
|---|---|---|---|---|
| M1 | Remover membro da área devolve os chamados dele à triagem sem pedir confirmação (conferido) | `servicos-config/index.php:316` | `data-confirmar` com a quantidade de chamados afetados | P |
| M2 | Transferir chamado, aprovar/fechar e "desligar todas as notificações" sem confirmação | `chamados/detalhe.php:80,148`, `conta/notificacoes.php:89` | `data-confirmar` | P |
| M3 | Trocar a área no filtro da fila mantém a categoria/atendente da área anterior (conferido) | `chamados/index.php:37` | Limpar categoria e atendente ao trocar a área, como em "Abrir chamado" | P |
| M4 | Em formulários com dois botões, o duplo clique só é bloqueado no primeiro (conferido) | `public/assets/js/app.js:124` | Usar `ev.submitter` | P |
| M5 | Campo "Opções" aparece para qualquer tipo de pergunta no construtor | `servicos-config/index.php:518,589` | Mostrar só para Lista e Múltipla escolha | P |
| M6 | Busca da portaria sem setas/Esc e sem atributos de acessibilidade | `app.js:140` | Navegação por teclado e ARIA de combobox | M |
| M7 | Textos fixos sem tradução: abas de Solicitações (conferido), mensagens de Portaria/Solicitações/SSMA, cabeçalhos de CSV, sufixos em Indicadores, confirmações de exclusão | `solicitacoes/index.php:21,44,50`, `PortariaController.php`, `indicadores/_conteudo.php` e outros | Passar por `t()` e completar `en.json`/`es.json` | M |
| M8 | Perfis `gestor_area` e `colaborador` não têm grupo do AD; a documentação cita `LDAP_DEFAULT_ROLE`, que não existe (conferido) | `.env.example:95-100`, `config/config.php:69-74`, `docs/10-PERFIS.md:121-133` | Criar os dois grupos na configuração e corrigir o documento | P |
| M9 | Documentação desatualizada: README sem chamados, perfis novos, API e manual; `docs/10` cita métodos que não existem; `docs/11` lista pendências já resolvidas; `docs/01` com contagem antiga de tabelas; manual sem Infleet e Tokens de API | `README.md`, `docs/01`, `docs/10`, `docs/11`, `docs/manual` | Atualizar | P |
| M10 | Tela "Tokens de API" sem tutorial nem botão "Como usar" | `app/Services/Tutoriais.php` | Acrescentar o módulo | P |
| M11 | API: cada consulta grava uma linha na auditoria (guardada por 60 meses) e o filtro incremental não tem índice em `updated_at` | `ApiController.php:115`, migrações | Agregar o uso por token/dia e criar índices nas colunas de data usadas pela API | P |
| M12 | Sem análise estática nem padrão de formatação; `ServicosConfigController` tem 929 linhas | raiz do projeto | PHPStan no CI; dividir o controlador por assunto (áreas, equipe, categorias, campos) | M |
| M13 | Tabela de áreas em Configurações com `colspan` desalinha colunas | `configuracoes/index.php:75` | Um controle por célula com atributo `form` | P |
| M14 | Trocar o idioma no meio de um formulário perde o que foi digitado | `app/helpers.php:40` | Avisar quando houver campos alterados | P |

## Prioridade baixa

| # | Ponto | Melhoria |
|---|---|---|
| B1 | Lista vazia por causa de filtro não oferece "Limpar filtros" | Botão junto à mensagem |
| B2 | Cabeçalhos de tabela não mostram nem permitem ordenação | Ordenar ao clicar, com `aria-sort` |
| B3 | Pré-visualização do formulário mostra os campos de todas as categorias juntos | Seletor de categoria na pré-visualização |
| B4 | Chaves duplicadas ou órfãs nos catálogos de tradução | Limpeza e verificação automática no CI |
| B5 | Avisos em PHP 8.5 nos testes (`DATE_RFC7231` em desuso, fixture sem `titulo`) | Corrigir |
| B6 | PR do dependabot para MariaDB 12.2 aberto | Decidir: manter 11.8 LTS e fechar, ou testar a 12.2 |

## Evoluções de produto sugeridas

- Aviso automático ao atendente e ao gestor quando o SLA estiver para vencer (hoje só aparece no filtro da fila).
- Campo próprio para o resultado do atendimento (ex.: código ERP no cadastro de Suprimentos), visível na lista do solicitante.
- Modelos de resposta para os atendentes e respostas por e-mail entrando como acompanhamento.

## Achados do Gemini descartados após conferência

- "Transferência perde o destino após erro de validação" (`ChamadosController.php:256`): o controlador lê os dados antigos antes de a view consumi-los; não reproduz pelo código. Vale um teste, mas não é defeito confirmado.
- "Falta `nonce` no layout simples": a política de segurança dessas telas usa `'self'` sem nonce; o nonce só existe na tela do mapa. Sem efeito.

## O que está bom e não precisa de ação

Controle de acesso por rota com teste automático, CSRF em todo POST, política de conteúdo sem inline, sessão com cookie seguro,
limite de tentativas de login, validação real do tipo de arquivo no upload, CPF cifrado, backup cifrado com cópia externa opcional,
API sem dados pessoais sensíveis.
