# Salinas Access Gate — PHP 8 + MariaDB

Sistema de controle de acesso (portaria) da **Salinas Gold Mineração LTDA**, reescrito para rodar
**sem Lovable Cloud / Supabase**: primeiro localmente (Windows) e depois em servidor
**Ubuntu + Webmin/Virtualmin** no domínio `accessgate.mycode.app.br`.

| Item | Antes (Lovable) | Agora |
|---|---|---|
| Front-end | TanStack Start + React | PHP renderizado no servidor + JS puro (sem build, sem Node) |
| Back-end | Supabase (RLS, Edge Functions) | PHP 8.1+ MVC próprio, sem dependências externas |
| Banco | PostgreSQL (Supabase) | MariaDB 10.6+ / MySQL 8 |
| Autenticação | Supabase Auth | Sessão PHP + bcrypt (cost 12) + bloqueio por tentativas · **opcional: Active Directory (LDAPS)** |
| Regras de acesso | RLS no banco + validações no navegador | RBAC no servidor + triggers append-only no banco |
| CPF | Texto puro mascarado por view | **Cifrado (libsodium) + índice cego HMAC-SHA256** |
| Fotos | Supabase Storage | `storage/uploads` fora da pasta pública, servidas só com login |

## Módulos

Painel · **Indicadores** (dashboard com Modo TV) · Portaria (entrada/saída, fotos, cadastro rápido) · Solicitações de acesso (interna, link externo 72 h, formulário público)
· Verificação SSMA · Quem está no site (+ lista de evacuação para impressão) · Pessoas e documentos (ASO, NRs, Integração, CNH)
· Veículos · Locais · Relatórios (CSV) · Auditoria · Usuários · Configurações e áreas · `/saude` para monitoramento (Zabbix).

## Instalação em servidor Linux com Docker

Dê dois cliques em `bin\INSTALAR-NO-SERVIDOR.bat` (padrão: `admin@192.168.4.5`, porta **222**).
O script empacota a pasta, envia por SSH e roda `docker/instalar.sh` no servidor. A senha do servidor é digitada por você.

O instalador:
- instala o Docker, se faltar;
- sobe a aplicação (PHP 8.3 + Apache) e o MariaDB 10.11;
- gera os segredos;
- cria o primeiro admin;
- agenda a expiração de solicitações e o backup diário.

Também dá para instalar direto do GitHub, no console do Ubuntu, com um token de leitura: veja a seção 3 do runbook.
Para atualizar, rode o mesmo `.bat` ou o mesmo comando. Detalhes no runbook `docs/05-DEPLOY-DOCKER-UBUNTU.md` (MD-TI-SIS-CBA-005).

## Tema claro e escuro

Botão **Tema** no rodapé do menu, na tela de login e no Modo TV. Ele alterna entre três opções:
- **Automático:** segue o tema do Windows ou do celular;
- **Escuro**;
- **Claro**.

A escolha fica em um cookie (`sag_tema`) e a página já abre no tema certo, sem piscar em branco.
Todas as cores vêm de tokens CSS, com contraste WCAG AA (4,5:1 ou mais) nos textos. Na impressão o sistema sempre usa o tema claro.

## Indicadores (modo dashboard)

Menu **Operação → Indicadores** (`/indicadores`), para Admin/TI, Supervisor e SSMA.

- **Filtros:** período (Hoje, Últimas 24 h, 7, 30 ou 90 dias), **"A partir de"** e **"Até"** com data e hora (até 366 dias), e local.
- **Atualização automática:** desligada, 5 s, 10 s, 30 s, 1 min ou 5 min. Atualiza só os números e os gráficos, sem recarregar a página.
  Pausa quando a aba fica oculta, e a escolha fica salva no navegador.
- **Indicadores:** presentes agora (por categoria), entradas, saídas e pessoas distintas com variação contra o período anterior, veículos,
  permanência média de visitantes e terceiros, solicitações pendentes, taxa de aprovação, verificações SSMA e entradas de externos sem solicitação.
- **Gráficos:** entradas por hora, dia ou semana e categoria; horário de pico; presença por local; empresas com mais entradas; solicitações por situação.
  Cada gráfico tem a opção "Ver dados em tabela".
- **Alertas:** presentes com documento vencido e pessoas dentro há mais horas que o limite de `alerta_permanencia_horas` (Configurações).
- **Modo TV** (`/indicadores?tv=1`): tela cheia, relógio e fonte maior, para monitores da portaria ou da Segurança Patrimonial.

Os gráficos são HTML/CSS gerados no servidor, sem bibliotecas externas e compatíveis com a CSP.
As cores foram validadas para daltonismo (protanopia e deuteranopia) e têm contraste de 3:1 ou mais.

## Regras de negócio aplicadas no servidor

Na entrada, o sistema bloqueia:

1. Pessoa inativa.
2. Terceiro com documento vencido.
3. Visitante ou terceiro sem solicitação **aprovada para hoje**. A solicitação é casada pelo CPF ou pelo nome e fica vinculada à pessoa na primeira entrada.
4. Atividade de risco sem verificação SSMA "Apto" vigente.
5. Veículo inativo.
6. Destino vazio, quando essa exigência está ligada nas Configurações.

Na saída, o sistema bloqueia pessoa ou veículo que não estejam presentes no local.

O sistema também emite avisos que não bloqueiam a operação: documento vencendo em até 30 dias, placa de outra empresa, veículo não comissionado para a mina e pessoa que já consta como presente.

`registros_acesso`, `registro_anexos`, `verificacoes_ssma` e `audit_log` são **imutáveis** (triggers `BEFORE UPDATE/DELETE`).
Toda movimentação é auditada por trigger, inclusive o IP do cliente.

## Perfis (RBAC)

| Permissão | Admin/TI | Supervisor | SSMA | Porteiro |
|---|:-:|:-:|:-:|:-:|
| Operar portaria | ✔ | | | ✔ |
| Ver/criar pessoas | ✔ | ✔ | ✔ | ✔ |
| Editar pessoas | ✔ | ✔ | ✔ | |
| Ver CPF completo | ✔ | ✔ | | |
| Documentos (ASO/NR) | ✔ | ✔ | ✔ | |
| Veículos: criar / editar | ✔ / ✔ | ✔ / ✔ | | ✔ / |
| Locais | ✔ | ✔ | | |
| Verificação SSMA | ✔ | | ✔ | |
| Indicadores (dashboard / Modo TV) | ✔ | ✔ | ✔ | |
| Relatórios e auditoria | ✔ | ✔ | | |
| Usuários e configurações | ✔ | | | |

Quem pode **autorizar solicitações**: Admin/TI, usuários marcados como **Segurança Patrimonial** e o **responsável pela área**. O porteiro nunca autoriza.

## Início rápido (local)

```bash
cp .env.example .env                       # ajuste DB_* e APP_URL
php bin/console.php gerar-chaves --gravar  # chaves do CPF (guarde em cofre!)
php bin/console.php migrar                 # cria tabelas, triggers e views
php bin/console.php criar-admin --email=ti@salinasgold.com.br --nome="TI Salinas"
php bin/console.php verificar              # checklist do ambiente
php -d post_max_size=72M -d upload_max_filesize=8M -S localhost:8080 -t public public/index.php
```

No Windows com XAMPP, basta dar dois cliques em `bin\INSTALAR-E-INICIAR.bat`. Guia completo do Windows em [docs/01-INSTALACAO-LOCAL-WINDOWS.md](docs/01-INSTALACAO-LOCAL-WINDOWS.md).
Deploy no Virtualmin em [docs/02-DEPLOY-VIRTUALMIN-UBUNTU.md](docs/02-DEPLOY-VIRTUALMIN-UBUNTU.md).
Migração dos dados do Supabase em [docs/03-MIGRACAO-SUPABASE.md](docs/03-MIGRACAO-SUPABASE.md).
Integração com Active Directory em [docs/04-INTEGRACAO-AD-LDAP.md](docs/04-INTEGRACAO-AD-LDAP.md).

## Estrutura

```
app/
  Core/          Núcleo: App, Env, Database (PDO), Router, View, Session, Csrf, Auth (RBAC), Crypto (CPF), Audit, RateLimiter
  Controllers/   Um controller por módulo
  Services/      Portaria (regras de bloqueio), Upload, Csv, Settings, ImportadorSupabase, Ldap + LdapUsuarios (AD)
  Views/         Templates PHP (saída sempre escapada com e())
  routes.php     Todas as rotas
bin/console.php  CLI: verificar, gerar-chaves, migrar, criar-admin, redefinir-senha, importar-supabase, expirar-solicitacoes, ldap-*
config/          Configuração lida do .env
database/        Migrações SQL (MariaDB/MySQL) + grants.sql (endurecimento opcional)
public/          DocumentRoot: index.php, .htaccess, CSS/JS, logos oficiais (img/logo-*.png) e favicons
storage/         uploads/, logs/, sessions/ (fora da web, precisa de escrita)
docs/            Instalação, deploy e migração
```

## Segurança (resumo)

- Prepared statements reais (PDO, sem emulação), CSRF em todo POST, saída escapada, CSP restritiva (sem inline), HSTS em HTTPS.
- Sessão HttpOnly/SameSite/Secure, regenerada no login, com expiração por inatividade. Troca de senha obrigatória no 1º acesso.
- Login com limite de 5 tentativas a cada 15 min por e-mail+IP. O formulário público tem limite por IP, honeypot e Turnstile opcional.
- Uploads validados por *magic bytes* + `getimagesize`, com nome aleatório e gravados fora da pasta pública.
- Exportações CSV protegidas contra injeção de fórmulas. A exportação com CPF completo fica auditada.

## Requisitos

PHP **8.1+** (recomendado 8.2/8.3) com `pdo_mysql`, `sodium`, `mbstring`, `fileinfo`, `curl` (opcional, para o Turnstile), `ldap` (opcional, para o AD) · MariaDB **10.6+** ou MySQL **8.0.16+**.
