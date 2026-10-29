# Perfis de Acesso, Múltiplos Perfis e Troca de Perfil Ativo

Data: 29/10/2026 · Salinas Gold Mineração LTDA · TI

O **Salinas Access Gate** adota controle de acesso baseado em perfis (RBAC) com suporte a **múltiplos perfis por usuário** e seleção rápida de **perfil ativo** na barra superior (inspirado no fluxo de alternância de perfis do GLPI). O menu lateral, os atalhos, os tutoriais e todas as autorizações do servidor seguem estritamente o **perfil ativo** na sessão, permitindo que um mesmo colaborador alterne com segurança entre atribuições administrativas, operacionais, de gestão de área ou de solicitante de serviços sem compartilhar contas.

---

## 1. Visão Geral dos Perfis do Sistema (`Auth::PAPEIS`)

O sistema possui seis perfis catalogados em `App\Core\Auth::PAPEIS` (migração `013_perfis_multiplos.sql`):

| Código (`perfil`) | Rótulo na interface | Finalidade principal | Página inicial ao ativar |
|---|---|---|---|
| `admin` | **Admin/TI** | Administração completa do sistema, usuários, locais, configurações globais, auditoria, LGPD e todas as áreas de serviços. | `/painel` |
| `supervisor` | **Supervisor** | Supervisão operacional e patrimonial: painel, pessoas (com CPF), veículos, locais, indicadores, relatórios, auditoria, solicitações de acesso e atendimento de chamados nas áreas em que é membro. | `/painel` |
| `ssma` | **SSMA** | Saúde, Segurança e Meio Ambiente: análise de aptidão em atividades de risco (`/ssma`), documentos, painel, indicadores, solicitações de acesso e atendimento de chamados nas áreas em que é membro. | `/painel` |
| `porteiro` | **Porteiro** | Operação de portaria (`/portaria`), presença, cadastro de pessoas e veículos na entrada, solicitações de acesso e atendimento de chamados nas áreas em que é membro. | `/painel` |
| `gestor_area` | **Gestor de área (serviços)** | Semi-administrador de serviços por área: gerencia o backlog (`Backlog das minhas áreas`) e configura categorias, SLAs, formulário dinâmico e atendentes exclusivamente das áreas em que é gestor (`servico_area_membros.papel = 'gestor'`). | `/chamados/fila` (ou `/chamados/meus` quando ainda sem área gerida) |
| `colaborador` | **Solicitante (serviços)** | Perfil de autoatendimento (Self-Service): exibe apenas o módulo **Serviços** (`Abrir chamado`, `Meus chamados`, `Notificações por e-mail` e `Duas etapas`). | `/chamados/meus` |

### 1.1. Estrutura de dados e API (`Auth`)
- **`usuario_perfis (usuario_id, perfil)`**: tabela com chave primária composta `(usuario_id, perfil)` e chave estrangeira para `usuarios(id) ON DELETE CASCADE`, armazenando todas as concessões da conta.
- **`usuarios.papel`**: permanece como o **perfil padrão de login** (o perfil que é ativado automaticamente quando o usuário entra no sistema), devendo sempre constar em `usuario_perfis`.
- **Métodos principais em `App\Core\Auth`**:
  - `Auth::perfis(): array` — lista os perfis concedidos ao usuário autenticado (relidos do banco a cada requisição);
  - `Auth::temMultiplosPerfis(): bool` — indica se o usuário possui dois ou mais perfis concedidos;
  - `Auth::perfilAtivo(): ?string` e `Auth::papel(): ?string` — retornam o perfil ativo validado na sessão (`perfil_ativo`), descartando automaticamente qualquer perfil revogado;
  - `Auth::trocarPerfil(string $perfil): bool` — valida se o perfil pertence a `Auth::perfis()`, regenera o ID da sessão (`Session::regenerate()`) e atualiza `perfil_ativo`;
  - `Auth::areasGeridas(?string $uid = null): ?array` — retorna `null` quando o perfil ativo é `admin` (representando todas as áreas) ou a lista de `area_id` em que o usuário possui `papel = 'gestor'` em `servico_area_membros`;
  - `Auth::gereArea(string $areaId, ?string $uid = null): bool` — retorna `true` para `admin` ativo ou quando `$areaId` pertence às áreas geridas pelo usuário.

---

## 2. Matriz de Perfis × Permissões (`Auth::PERMISSOES`)

Todas as verificações de `Auth::can($permissao)` avaliam o **perfil ativo** na sessão:

| Permissão | `admin` | `supervisor` | `ssma` | `porteiro` | `gestor_area` | `colaborador` | Observações de escopo |
|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| `portaria.operar` | ✔ | — | — | ✔ | — | — | Operação de entrada e saída em `/portaria` |
| `painel.ver` | ✔ | ✔ | ✔ | ✔ | — | — | Painel operacional (`/painel`) |
| `pessoas.ver` | ✔ | ✔ | ✔ | ✔ | — | — | Consulta de pessoas (`/pessoas`) |
| `pessoas.criar` | ✔ | ✔ | ✔ | ✔ | — | — | Cadastro de novas pessoas |
| `pessoas.editar` | ✔ | ✔ | ✔ | — | — | — | Edição de dados cadastrais de pessoas |
| `pessoas.excluir` | ✔ | — | — | — | — | — | Exclusão de cadastro de pessoa |
| `pessoas.ver_cpf` | ✔ | ✔ | — | — | — | — | Exibição de CPF desmascarado |
| `pessoas.vincular_usuario` | ✔ | — | — | — | — | — | Vínculo entre pessoa e conta de usuário |
| `lgpd.titular` | ✔ | — | — | — | — | — | Relatório e anonimização LGPD do titular |
| `documentos.gerir` | ✔ | ✔ | ✔ | — | — | — | Cadastro e renovação de documentos (ASO, NR etc.) |
| `documentos.excluir` | ✔ | — | ✔ | — | — | — | Exclusão de documentos de pessoas |
| `veiculos.ver` | ✔ | ✔ | ✔ | ✔ | — | — | Consulta da frota (`/veiculos`) |
| `veiculos.criar` | ✔ | ✔ | — | ✔ | — | — | Cadastro de novos veículos |
| `veiculos.editar` | ✔ | ✔ | — | — | — | — | Edição e comissionamento de veículos |
| `veiculos.excluir` | ✔ | — | — | — | — | — | Exclusão de veículos |
| `infleet.ver` | ✔ | ✔ | — | — | — | — | Consulta de telemetria e percursos Infleet |
| `infleet.gerir` | ✔ | — | — | — | — | — | Sincronização manual e configuração Infleet |
| `locais.gerir` | ✔ | ✔ | — | — | — | — | Cadastro e edição de portarias/locais (`/locais`) |
| `locais.excluir` | ✔ | — | — | — | — | — | Exclusão de locais |
| `registros.ver` | ✔ | ✔ | ✔ | ✔ | — | — | Histórico de acessos e presença (`/presenca`) |
| `solicitacoes.ver` | ✔ | ✔ | ✔ | ✔ | — | — | Solicitações de acesso de visitantes/terceiros |
| `solicitacoes.criar` | ✔ | ✔ | ✔ | ✔ | — | — | Abertura e link externo de solicitações de acesso |
| `ssma.verificar` | ✔ | — | ✔ | — | — | — | Triagem de aptidão em atividades de risco (`/ssma`) |
| `indicadores.ver` | ✔ | ✔ | ✔ | — | — | — | Indicadores operacionais e Modo TV (`/indicadores`) |
| `relatorios.ver` | ✔ | ✔ | — | — | — | — | Relatórios e exportação CSV (`/relatorios`) |
| `auditoria.ver` | ✔ | ✔ | — | — | — | — | Consulta à trilha de auditoria (`/auditoria`) |
| `usuarios.gerir` | ✔ | — | — | — | — | — | Administração de contas e perfis (`/usuarios`) |
| `configuracoes.gerir` | ✔ | — | — | — | — | — | Parâmetros globais, expediente, e-mail e retenção (`/configuracoes`) |
| `chamados.abrir` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Abrir chamados (`/chamados/abrir`) e acompanhar em `/chamados/meus` |
| `chamados.atender` | ✔ | Cond.¹ | Cond.¹ | Cond.¹ | Cond.² | — | Acessar fila (`/chamados/fila`) e atender chamados |
| `chamados.configurar_area` | — | — | — | — | Cond.² | — | Configurar categorias, SLAs, formulários e atendentes das áreas geridas (`/servicos/config`) |
| `chamados.configurar` | ✔ | — | — | — | — | — | Administração completa de todas as áreas de suporte (`/servicos/config`) |

> **Cond.¹ (`supervisor`, `ssma`, `porteiro` em `chamados.atender`)**: requer vínculo do usuário em `servico_area_membros` (`atendente` ou `gestor`). Quando o perfil ativo é `colaborador` (**Solicitante**), `chamados.atender` retorna sempre `false`, mesmo que a conta possua vínculo em `servico_area_membros` (impedindo acesso à fila, notas internas ou anexos restritos de chamados de terceiros enquanto estiver no perfil Solicitante).
>
> **Cond.² (`gestor_area` em `chamados.atender` e `chamados.configurar_area`)**: requer vínculo como **`gestor`** (`servico_area_membros.papel = 'gestor'`) em pelo menos uma área de suporte (`Auth::areasGeridas() !== []`). Tanto a fila (`Backlog das minhas áreas`), quanto o detalhe/atendimento de chamados (`ChamadosController::atende`) e a tela **Áreas e formulários** (`/servicos/config`) ficam restritos às áreas retornadas por `Auth::areasGeridas()`.
>
> **Marcação `Segurança Patrimonial` (`Auth::isSegurancaPatrimonial()`)**: permanece efetiva apenas quando o perfil ativo não é `colaborador` nem `gestor_area`.

---

## 3. Como Funciona a Troca de Perfil Ativo

1. **Seletor no topo da tela**:
   - Quando o usuário autenticado possui **dois ou mais perfis** (`Auth::temMultiplosPerfis()`), o cabeçalho exibe, ao lado do seletor de idioma, um menu recolhível `<details>` com `Perfil: <Rótulo do perfil ativo>`;
   - Ao abrir o seletor, todos os perfis concedidos à conta são listados com marcação visual (`✓`) no perfil ativo. Quando a conta possui apenas um perfil, o seletor não é exibido.
2. **Requisição e segurança (`POST /conta/perfil`)**:
   - A troca é processada por `App\Controllers\PerfilController::trocar` mediante requisição `POST /conta/perfil` protegida por token CSRF (`_csrf`);
   - O servidor revalida se o `perfil` solicitado pertence a `Auth::perfis()`. Tentativas de ativar um perfil não concedido à conta retornam HTTP `403`;
   - Quando a troca é válida, a sessão tem seu identificador regenerado (`Session::regenerate()`) para mitigar fixação de sessão e o evento é registrado em `audit_log` (`entidade = 'auth'`, `acao = 'TROCA_PERFIL'`, contendo `anterior` e `perfil`).
3. **Redirecionamento após a troca**:
   - O formulário envia o caminho interno atual no campo oculto `voltar`;
   - Se o novo perfil ativo possuir permissão (e escopo de área, no caso de detalhe de chamado) para visualizar a página atual, o usuário permanece na mesma tela já sob a visão do novo perfil (limpando filtros de área alheios quando alterna para `gestor_area`);
   - Caso a página anterior não pertença ao escopo do novo perfil, o usuário é redirecionado para a página inicial correspondente ao novo perfil (`/chamados/meus` para `colaborador` ou `gestor_area` sem área gerida, `/chamados/fila` para `gestor_area` com área gerida, ou `/painel` para os demais).
4. **Verificação em duas etapas (MFA) para contas com concessão `admin`**:
   - Para cumprir o controle ISO/IEC 27001 A.8.5, se uma conta local possuir o perfil `admin` entre os seus perfis concedidos (`in_array('admin', Auth::perfis(), true)`) e o parâmetro `mfa_obrigatorio_admin` estiver ativo, a exigência de cadastrar e manter o MFA permanece obrigatória **mesmo quando o perfil ativo no momento for outro** (como `colaborador` ou `gestor_area`).

---

## 4. Como o Admin/TI Concede Múltiplos Perfis (`/usuarios`)

Em **Administração → Usuários** (`/usuarios`):
1. Ao criar ou editar um usuário (`UsuariosController`), a seção de perfis apresenta:
   - **Perfis concedidos (`perfis[]`)**: caixas de seleção para marcar um ou mais perfis entre os seis disponíveis (`Admin/TI`, `Supervisor`, `SSMA`, `Porteiro`, `Solicitante (serviços)` e `Gestor de área (serviços)`);
   - **Perfil padrão ao entrar (`papel`)**: seletor que define qual perfil será ativado automaticamente no login. O servidor exige que o perfil padrão esteja marcado entre os **Perfis concedidos**.
2. **Gravação transacional e travas de proteção**:
   - As alterações em `usuarios.papel` e na tabela `usuario_perfis` são gravadas dentro da mesma transação de banco de dados;
   - O sistema impede que o administrador logado remova a própria concessão `admin` e bloqueia a remoção do perfil `admin` ou a desativação da conta quando ela for o **último administrador ativo** em `usuario_perfis`.
3. **Configuração completa de um Gestor de área (`gestor_area`)**:
   - Para que um usuário atue como **Gestor de área (serviços)**, são necessários **dois passos complementares**:
     1. Em **Administração → Usuários** (`/usuarios`), conceder o perfil **Gestor de área (serviços)** (`gestor_area`) à conta;
     2. Em **Serviços → Áreas e formulários** (`/servicos/config`), selecionar cada área sob responsabilidade do colaborador e vinculá-lo no cartão **Equipe da área** com o papel **Gestor** (`servico_area_membros.papel = 'gestor'`).
   - Na aplicação da migração `013_perfis_multiplos.sql`, todos os usuários que já estavam cadastrados como `gestor` em `servico_area_membros` recebem automaticamente a concessão `gestor_area` em `usuario_perfis`.

---

## 5. Mapeamento por Grupos do Active Directory (`Ldap` e `LdapUsuarios`)

Quando a autenticação via Active Directory está habilitada (`LDAP_ENABLED=true`), o serviço `App\Services\LdapUsuarios` permite conceder automaticamente múltiplos perfis a partir dos grupos (`memberOf`) do usuário no diretório:

### 5.1. Variáveis de ambiente (`.env`)
| Variável no `.env` | Perfil concedido em `usuario_perfis` |
|---|---|
| `LDAP_GROUP_ADMIN` | `admin` (Admin/TI) |
| `LDAP_GROUP_SUPERVISOR` | `supervisor` (Supervisor) |
| `LDAP_GROUP_SSMA` | `ssma` (SSMA) |
| `LDAP_GROUP_PORTEIRO` | `porteiro` (Porteiro) |
| `LDAP_GROUP_GESTOR_AREA` | `gestor_area` (Gestor de área (serviços)) |
| `LDAP_GROUP_COLABORADOR` | `colaborador` (Solicitante (serviços)) |
| `LDAP_DEFAULT_ROLE` | Perfil atribuído quando o usuário não pertence a nenhum dos grupos acima (ex.: `colaborador`; vazio = exige grupo mapeado) |

Cada variável aceita o **CN** do grupo (ex.: `GRP_PORTARIA_GESTORES`) ou o **DN** completo (ex.: `CN=GRP_PORTARIA_GESTORES,OU=Grupos,DC=salinas,DC=local`).

### 5.2. Regras de provisionamento e sincronização (`LDAP_SYNC_ROLES`)
1. **Acúmulo de perfis**: todos os grupos mapeados aos quais o usuário pertence no Active Directory são acumulados e gravados em `usuario_perfis`. Por exemplo, um usuário presente simultaneamente em `LDAP_GROUP_SUPERVISOR`, `LDAP_GROUP_GESTOR_AREA` e `LDAP_GROUP_COLABORADOR` recebe os três perfis e pode alternar entre eles no topo da tela.
2. **Definição do perfil padrão (`usuarios.papel`)**:
   - No primeiro login (provisionamento automático), o perfil padrão é escolhido entre os perfis concedidos seguindo a ordem de precedência: `admin` → `supervisor` → `ssma` → `porteiro` → `gestor_area` → `colaborador`;
   - Nos logins seguintes com `LDAP_SYNC_ROLES=true`, as concessões em `usuario_perfis` são atualizadas conforme os grupos atuais do AD; se o `usuarios.papel` anterior continuar presente entre os perfis concedidos, ele é preservado como padrão (caso contrário, adota o primeiro perfil da ordem de precedência).
   - Quando `LDAP_SYNC_ROLES=false`, os perfis definidos manualmente pelo Admin/TI em `/usuarios` não são sobrescritos nos logins subsequentes.
3. **Proteção do último Admin e vínculo de área**:
   - A sincronização de grupos do AD nunca remove a concessão `admin` se a conta for o último administrador ativo do sistema;
   - O grupo `LDAP_GROUP_GESTOR_AREA` concede o perfil `gestor_area` à conta, mas a definição de **quais áreas de suporte** o usuário gerencia permanece controlada no banco pelo vínculo `papel = 'gestor'` em `servico_area_membros` (configurado pelo Admin/TI em **Serviços → Áreas e formulários**).
