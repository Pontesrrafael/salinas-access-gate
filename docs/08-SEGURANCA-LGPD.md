# Revisão de segurança e LGPD

**Documento:** MD-TI-SIS-CBA-008 · Rev. 00 · Salinas Access Gate
**Escopo:** aplicação (PHP), banco (MariaDB), contêineres (Docker), instalação, backup e tratamento de dados pessoais
**Data da revisão:** 10/10/2026 · **Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 1. Resumo

| Frente | Resultado |
|---|---|
| Instalação | O instalador **pergunta o usuário e a senha do banco** e a senha do root. A senha é validada e confirmada, e Enter gera uma senha forte. Há script para **trocar as senhas** depois |
| Segurança | Base sólida já existente: CSRF, CSP sem inline, prepared statements, upload validado, auditoria imutável. **11 achados corrigidos** nesta revisão (seção 3). Os pendentes são de infraestrutura (seção 5) |
| LGPD | **Aviso de privacidade**, **relatório do titular**, **anonimização**, **retenção automática**, fotos **sem GPS/EXIF** e **minimização** de CPF e de exportações. Faltam a nomeação formal do encarregado e o RIPD (seção 5) |

## 2. Credenciais do banco na instalação

**Docker (`docker/instalar.sh`)**

Na 1ª instalação o instalador pergunta:

1. **Usuário do banco para a aplicação** (padrão `sag_app`): 3–32 caracteres, minúsculas, números e `_`. Os nomes `root` e `sag_bi` não são aceitos.
2. **Senha desse usuário**, digitada duas vezes e sem aparecer na tela. Enter gera uma senha aleatória de 48 caracteres. Regras:
   - de 12 a 64 caracteres;
   - maiúscula, minúscula e número;
   - só letras, números e `@ % + = : , . _ ! * ~ ^ ( ) -`. Ficam de fora espaço, aspas, `$`, `#`, `\` e crase, que quebrariam arquivos `.env` e comandos SQL.
3. **Senha do root do MariaDB**: mesmas regras e deve ser diferente da senha da aplicação.

As senhas são gravadas em `docker/db.env` e `docker/app.env` (`chmod 600`, só root) e **não aparecem no terminal nem no log**.

Sem terminal (automação/CI): `SAG_DB_USUARIO`, `SAG_DB_SENHA` e `SAG_DB_ROOT_SENHA`. Nas atualizações nada é perguntado, e os segredos existentes são mantidos.

**Trocar depois** (recomendado a cada 12 meses ou na saída de alguém da TI):

```bash
sudo bash /opt/salinas-access-gate/docker/alterar-senha-banco.sh app    # usuário da aplicação (reinicia o app)
sudo bash /opt/salinas-access-gate/docker/alterar-senha-banco.sh root   # root do MariaDB
sudo bash /opt/salinas-access-gate/docker/alterar-senha-banco.sh bi     # usuário do Power BI/Metabase
```

**Windows/XAMPP (`bin\INSTALAR-E-INICIAR.bat`)**: na 1ª instalação pergunta o usuário e a senha, com as mesmas regras, e envia o SQL ao MariaDB pela entrada padrão. Assim a senha não aparece na lista de processos.

## 3. Revisão de segurança

### 3.1 Achados corrigidos

| # | Severidade | Achado | Correção |
|---|---|---|---|
| S1 | Alta | Backups guardavam o dump (CPF cifrado) **junto do `app.env` com a chave**: uma cópia vazada no NAS expunha tudo | Backups **cifrados** (AES-256-CBC, PBKDF2 200 mil iterações) com `docker/backup.chave`, criada pelo instalador. A chave fica fora da pasta de backup |
| S2 | Média | O código da aplicação pertencia ao usuário do Apache (`www-data`): uma falha poderia alterar o próprio código | Código do root, somente leitura para o Apache. Só `storage/` é gravável. Teste: `www-data` não consegue escrever em `app/` |
| S3 | Média | Troca ou redefinição de senha **não encerrava** outras sessões abertas | Sessões anteriores à troca são encerradas (`senha_alterada_em` × hora do login) |
| S4 | Média | A sessão podia durar indefinidamente com uso contínuo | Duração máxima de **12 h** (um turno), em `SESSION_MAX_HOURS` |
| S5 | Média | O limite de login era só por e-mail+IP, o que permitia tentativa distribuída de vários IPs | Novo limite **por conta**, de qualquer IP (15 em 15 min) |
| S6 | Média | Senha mínima de 8 caracteres, aceitando "Salinas2026" | Mínimo de **10 caracteres** e bloqueio de senhas previsíveis (empresa, "senha", "admin", "portaria", sequências) |
| S7 | Baixa | Cabeçalhos HTTP incompletos | `object-src 'none'`, `worker-src 'self'`, COOP/CORP `same-origin`, `X-Permitted-Cross-Domain-Policies`, `X-Robots-Tag: noindex` e `Permissions-Policy` ampliada |
| S8 | Baixa | O instalador usava usuário e senha fixos/gerados, sem escolha da TI | Perguntas com validação e script de troca (seção 2) |
| S9 | Baixa | `.gitignore` não cobria `docker/*.env` | Coberto, junto com `docker/backup.chave`, também no `.dockerignore` |
| S10 | Baixa | Pasta `/opt/salinas-access-gate` sem dono definido | `root:root`, com `docker/` em `750` |
| S11 | Baixa | O QR do convite entregava o **CPF completo** ao navegador do porteiro, que não tem permissão de ver CPF | O CPF fica no servidor: o cadastro rápido pelo QR usa o documento da solicitação sem exibi-lo |

### 3.2 Controles verificados (sem alteração)

| Área | Situação |
|---|---|
| SQL | PDO com prepared statements reais em toda a aplicação. Nomes de tabela e coluna passam por `ident()` |
| XSS | Toda saída dinâmica passa por `e()`. No JavaScript, `esc()` antes de `innerHTML`. Varredura das views sem achados |
| CSRF | Token por sessão em todo POST, por formulário ou cabeçalho `X-CSRF-Token` |
| Sessão | Cookie HttpOnly e SameSite=Lax (Secure em HTTPS), `use_strict_mode`, ID regenerado no login, expiração por inatividade |
| Upload | Tipo real (finfo) + `getimagesize`, nome aleatório, fora da pasta pública, servido só com login e `nosniff` |
| LDAP | `ldap_escape` nos filtros, TLS obrigatório, senha da conta de serviço cifrada |
| Auditoria | `audit_log`, `registros_acesso`, `verificacoes_ssma` e `registro_anexos` append-only por trigger |
| CSV | Proteção contra injeção de fórmulas (`=`, `+`, `-`, `@`) |
| Redirecionamentos | Só para caminhos internos |
| Formulário público | Limite por IP, honeypot, Turnstile opcional |
| Erros | `display_errors` desligado em produção |
| Rede | MariaDB sem porta publicada (opcional para o BI) e `root@'%'` removido quando publicado |
| Apache | `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`, `expose_php Off` |

## 4. Revisão LGPD

### 4.1 Inventário (registro das operações de tratamento — art. 37)

| Dado | Onde | Finalidade | Base legal | Quem acessa | Retenção |
|---|---|---|---|---|---|
| Nome, empresa, função, matrícula | `pessoas` | Identificação na portaria | Art. 7º, IX (legítimo interesse — segurança) | Portaria, SSMA, supervisão, TI | Visitante/terceiro: anonimizado após 24 meses sem acesso (configurável) |
| CPF | `pessoas.cpf_enc` (cifrado) + índice cego | Identificação inequívoca e busca | Art. 7º, IX | Busca: todos os perfis. Ver completo: admin e supervisor, com motivo auditado | Igual ao cadastro |
| RG, telefone, e-mail, WhatsApp | `pessoas`, `solicitacoes_acesso` | Contato e autorização da visita | Art. 7º, IX | Perfis internos | Igual ao cadastro |
| Documentos de SSMA (ASO, NR, integração) | `documentos` (tipo, número, validade) | Liberação para áreas de risco | Art. 7º, II (NRs) | SSMA, portaria (situação) | Número apagado na anonimização |
| Entradas e saídas | `registros_acesso` | Segurança, evacuação, auditoria | Art. 7º, II e IX | Perfis internos, BI (sem documento) | Mantido. Após a anonimização, sem identificar a pessoa |
| Fotos da portaria | `storage/uploads` | Prova do acesso | Art. 7º, IX | Perfis com acesso a registros | **12 meses** (configurável), sem metadados |
| IP e ações dos usuários | `audit_log` | Segurança da informação (art. 46) | Art. 7º, IX | Admin e supervisão | Imutável |
| Cadastro local offline | Navegador do posto (IndexedDB) | Operar sem conexão | Art. 7º, IX | Posto da portaria | Atualizado a cada 10 min e apagado no logout. **Sem CPF ou contato** |

### 4.2 Implementado nesta revisão

| # | Item | Como funciona |
|---|---|---|
| L1 | **Aviso de privacidade** (art. 9º) | Página pública `/privacidade`, gerada a partir das Configurações. Traz controlador, finalidades, bases legais, compartilhamento, prazos, segurança, direitos e encarregado. Fica linkada no rodapé, no login e no formulário público |
| L2 | **Ciência em vez de "autorizo"** | O formulário dizia "Autorizo o tratamento", o que sugere consentimento como base legal. Consentimento pode ser revogado, e a portaria precisa dos dados mesmo assim. Agora o texto registra *ciência do aviso*, e a base é obrigação legal ou legítimo interesse |
| L3 | **Relatório do titular** (art. 18, I, II e V) | Na ficha da pessoa (admin), baixa um JSON com cadastro, CPF, documentos, solicitações, verificações SSMA e acessos. Pede protocolo/motivo e é auditado (`RELATORIO_TITULAR`) |
| L4 | **Anonimização** (art. 16 e 18, IV e VI) | Apaga de forma irreversível nome, CPF, RG, contatos, observações, número de documentos, dados das solicitações e fotos. Mantém o histórico de acesso sem identificação. Bloqueada para colaborador (retenção do RH), pessoa vinculada a usuário ou pessoa presente. Auditada (`ANONIMIZACAO_LGPD`) |
| L5 | **Retenção automática** | `php bin/console.php lgpd-retencao` roda todo dia 1 às 02:15 pelo cron e tem modo `--simular`. Anonimiza visitantes e terceiros sem acesso há N meses e solicitações antigas que não viraram visita, e apaga fotos com mais de M meses. Prazos em *Configurações → Privacidade* (padrão 24 e 12 meses) |
| L6 | **Fotos sem metadados** | GPS, modelo do aparelho, data e autor (EXIF, XMP, IPTC, textos PNG) são removidos no upload, sem recodificar a imagem. Só a orientação é mantida |
| L7 | **Minimização** | Exportação em massa do cadastro restrita a admin/supervisor. Documento da solicitação mascarado para quem não vê CPF. QR sem CPF para o porteiro (S11) |
| L8 | **Encarregado** | Nome e e-mail do DPO configuráveis e exibidos no aviso |

### 4.3 Atendimento a pedidos do titular (procedimento)

1. O pedido chega pela portaria, pelo RH ou pelo e-mail do encarregado. Registre um **protocolo**.
2. Confirme a identidade do titular (documento com foto).
3. **Acesso/portabilidade:** abra a pessoa → *Privacidade (LGPD)* → **Baixar dados da pessoa**, informando o protocolo. Envie o arquivo ao titular por canal seguro.
4. **Correção:** edite o cadastro (alteração auditada).
5. **Eliminação:** se não houver obrigação legal de guarda, use **Anonimizar**, informando o protocolo.
6. Responda em até **15 dias** (art. 19) e arquive o protocolo.

### 4.4 Incidentes com dados pessoais

Em caso de vazamento ou acesso indevido:

1. Isole o sistema e preserve as evidências: `audit_log`, logs do Apache e backup.
2. Acione o encarregado e a TI.
3. Avalie o risco aos titulares.
4. Se houver risco ou dano relevante, comunique a **ANPD e os titulares em até 3 dias úteis** (Resolução CD/ANPD nº 15/2024).
5. Documente a lição aprendida.

## 5. Pendências (responsável: TI + encarregado)

| Item | Por quê |
|---|---|
| **HTTPS** (proxy reverso com certificado) | As senhas trafegam em texto claro na rede interna (porta 222). Também é pré-requisito para a câmera ao vivo e para a tela offline |
| **Nomear formalmente o encarregado (DPO)** e publicar o contato | Art. 41. O campo já existe nas Configurações |
| **RIPD** (relatório de impacto) do controle de acesso | Recomendado para tratamento de larga escala de visitantes e terceiros (art. 38) |
| **Revisar os prazos de retenção** com Jurídico e SSMA | ASO e treinamentos têm prazos próprios. Os 24 e 12 meses são o ponto de partida |
| **Usuário de migração separado** no MariaDB | Hoje o usuário da aplicação tem ALL no banco e, em tese, poderia remover os triggers de imutabilidade |
| **Cópia dos backups fora do servidor** (NAS), sem a `backup.chave` | A cópia já sai cifrada. Falta o rsync agendado |
| **Política de acesso** (revisão trimestral de usuários e perfis) | Controle de governança |
| **Treinamento LGPD** para portaria e SSMA | O formulário e o aviso não substituem a orientação de quem coleta os dados |
| **2FA** para admin (TOTP) | Reduz o risco de senha vazada no perfil mais crítico |

## 6. Validação desta revisão

| Suíte | Resultado |
|---|---|
| E2E | 81/81 |
| Pacote Portaria | 49/49 |
| Interface no navegador | 29/29 |
| LDAP pela tela | 31/31 |
| **Segurança + LGPD** | 42/42. Cobre: cabeçalhos, política de senha, encerramento de sessões na troca, limite por conta, exportação restrita, documento mascarado, QR sem CPF, foto sem GPS (com orientação mantida), aviso de privacidade, relatório do titular, anonimização com bloqueios, retenção simulada e real |
| Docker (PHP 8.5 + MariaDB 11.8) | Instalação **interativa** com usuário e senha escolhidos (incluindo recusa de usuário inválido, senha fraca, caractere proibido e confirmação divergente). Código somente leitura, backups cifrados e decifráveis com a chave, troca de senha de app, root e BI com a aplicação saudável, E2E 81/81 e pacote Portaria 49/49 |
