# Solicitação de Serviços e Chamados por Área

Data: 28/10/2026 · Salinas Gold Mineração LTDA · TI

Módulo de solicitação de serviços e acompanhamento de chamados internos (inspirado no fluxo ITIL/GLPI da Salinas), integrado ao padrão visual e de governança do **Salinas Access Gate**. Permite que áreas de suporte — como **SSMA**, **Facilities**, **Meio Ambiente**, **Suprimentos** e novas áreas cadastradas pelo Admin/TI — recebam, priorizem e solucionem demandas com controle de SLA, horário comercial, notificações por e-mail, pesquisa de satisfação e retenção LGPD.

---

## 1. Guia do Administrador (`chamados.configurar` e `configuracoes.gerir`)

O gerenciamento das áreas, categorias e formulários fica em **Gestão → Configuração de Chamados** (`/servicos/config`), e os parâmetros globais de expediente, e-mail e retenção ficam em **Gestão → Configurações** (`/configuracoes`), restritos ao perfil **Admin/TI**. Todas as alterações exigem token CSRF e são registradas na trilha de auditoria (`audit_log`).

### 1.1. Cadastrar e editar áreas de suporte
1. No bloco **Nova área de suporte**, informe:
   - **Nome da área** (ex.: `Manutenção Elétrica`, `Facilities`, `Suprimentos`);
   - **Identificador (slug)**: gerado automaticamente a partir do nome quando deixado em branco;
   - **Cor de identificação**: código hexadecimal (`#024B37` por padrão);
   - **Ordem de exibição** e **Descrição** resumida dos serviços atendidos.
2. Clique em **Adicionar área**.
3. Na listagem **Áreas de atendimento**, clique em **Configurar** na linha da área desejada para gerenciar sua equipe, suas categorias e seu formulário dinâmico, ou em **Editar** para alterar dados cadastrais e ativar/desativar a área.

### 1.2. Vincular equipe da área (Atendentes e Gestores)
1. Com a área selecionada, acesse o cartão **Equipe da área**.
2. Escolha um **Usuário ativo** do sistema e defina o **Papel na área**:
   - **Atendente**: visualiza a fila da área, assume chamados, registra acompanhamentos públicos, notas internas e soluções;
   - **Gestor**: além das atribuições de atendente, coordena a fila e distribui chamados entre os membros da equipe.
3. Clique em **Vincular membro**. Para alterar o papel de um membro já listado, basta selecioná-lo novamente com o novo papel. Para desligá-lo da fila, clique em **Remover**.

### 1.3. Categorias em árvore, prazos de SLA e horário comercial
Cada área possui sua própria árvore hierárquica de categorias e subcategorias (ex.: `Infraestrutura › Elétrica › Iluminação`):
1. No bloco **Nova categoria**, preencha:
   - **Nome da categoria**;
   - **Categoria pai**: selecione `— Raiz (sem categoria pai) —` para categoria de 1º nível ou escolha uma categoria existente da área para criar uma subcategoria;
   - **Urgência padrão** (`1 — Muito Baixa` a `5 — Muito Alta`, padrão `3 — Média`);
   - **SLA de resposta** e **SLA de solução**: escolha um dos atalhos rápidos (`15 min`, `30 min`, `1 h`, `2 h`, `4 h`, `8 h`, `24 h`, `72 h`) ou informe o tempo exato em minutos no campo numérico ao lado;
   - **Regime de contagem do SLA** (`usar_horario_comercial`): define se os prazos da categoria correm apenas dentro do expediente útil configurado ou em horas corridas (24x7).
2. Clique em **Adicionar categoria**.

### 1.4. Construtor de formulário dinâmico por área e categoria
O construtor permite criar perguntas extras específicas para uma área inteira ou apenas para uma categoria:
1. No cartão **Construtor de formulário**, informe:
   - **Rótulo do campo** (pergunta exibida ao solicitante);
   - **Tipo de resposta**:
     - `Texto curto` (até 500 caracteres);
     - `Texto longo` (múltiplas linhas, até 5.000 caracteres);
     - `Número` (inteiro ou decimal);
     - `Data` (`AAAA-MM-DD`);
     - `Lista (escolha única)` — exige informar as opções (uma por linha);
     - `Múltipla escolha` — caixas de seleção permitindo marcar uma ou mais opções da lista;
     - `Sim / Não`;
     - `Anexo` — validado no servidor via `App\Services\Upload` (PDF, JPG, PNG ou WEBP até 4 MB).
   - **Aplicar na categoria**: deixe em `— Todas as categorias da área —` para exibir o campo em qualquer chamado da área, ou escolha uma categoria específica;
   - **Opções**: uma opção por linha (obrigatório para `Lista` e `Múltipla escolha`);
   - **Texto de ajuda** e caixa **Preenchimento obrigatório**.
2. Na tabela de campos configurados, utilize os botões **↑** e **↓** para reordenar as perguntas, **Desativar/Ativar** para suspender ou reabilitar um campo sem perder o histórico, ou **Editar** para ajustar rótulo e opções.
3. As respostas de chamados já abertos são gravadas com cópia do rótulo do momento da abertura (`chamado_respostas`), preservando o histórico mesmo que o formulário seja alterado depois.

### 1.5. Calendário de expediente, notificações SMTP e retenção (Etapa 2)
Em **Gestão → Configurações** (`/configuracoes`, seção **Chamados**) e no arquivo `.env`:
1. **Calendário de horário comercial**:
   - `horario_comercial_ativo`: ativa o cálculo em minutos úteis para categorias com `usar_horario_comercial = 1`;
   - `horario_dias`: dias úteis no padrão ISO (`1,2,3,4,5` = segunda a sexta-feira);
   - `horario_inicio` e `horario_fim`: janela diária de expediente (padrão `07:00` às `17:00`, em Horário de Mato Grosso – UTC-4);
   - `feriados`: datas `AAAA-MM-DD` (separadas por vírgula ou linha) que não contam no relógio de SLA.
2. **Notificações por e-mail (fila assíncrona)**:
   - Configure no `.env` os parâmetros `SMTP_HOST`, `SMTP_PORT` (`587`), `SMTP_SECURITY` (`tls`, `ssl` ou `nenhuma`), `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL` e `SMTP_FROM_NAME`;
   - Ative `email_ativo = 1` em **Configurações**;
   - As ações web apenas enfileiram os avisos na tabela `email_fila` (`App\Services\Notificacoes::chamado`), sem travar a resposta ao usuário. O disparo SMTP ocorre em segundo plano pelo comando `php bin/console.php enviar-emails` (agendado no cron a cada 5 minutos), com até 5 tentativas em espera crescente e proteção contra vazamento de senha em `ultimo_erro`;
   - **Minimização de dados (LGPD)**: os e-mails contêm apenas metadados essenciais (número, título, área, categoria, status, prioridade) e o link direto para o chamado. Notas internas, descrições livres, beneficiários e respostas de formulário nunca são enviados por e-mail.
3. **Fechamento automático e retenção LGPD**:
   - `chamados_fechar_dias` (padrão: `7` dias): chamados em `solucionado` sem interação do solicitante após esse prazo são fechados pelo comando diário `php bin/console.php fechar-chamados`, marcando `fechado_automaticamente = 1`;
   - `retencao_chamados_meses` (padrão: `24` meses, mínimo `6` meses): no job diário `php bin/console.php aplicar-retencao`, chamados com status `fechado` e `fechado_em` anterior ao prazo têm seus anexos apagados (`chamado_anexos` e arquivos em disco), `descricao` e `beneficiario_nome` definidos como `NULL`, e textos de `chamado_respostas` e `chamado_acompanhamentos` substituídos por `[removido por retenção]`, preservando número, área, categoria, datas, prioridade e nota de satisfação para indicadores estatísticos;
   - Registros da `email_fila` já enviados há mais de 30 dias também são expurgados automaticamente pela rotina de retenção.

---

## 2. Guia para Atendentes e Gestores de Área (`chamados.atender`)

Usuários vinculados a pelo menos uma área de suporte (ou com perfil **Admin/TI**) acessam **Serviços → Fila de atendimento**:

### 2.1. Triagem, filtros e indicadores da fila
- Filtre os chamados por **Área**, **Status**, **Categoria**, **Prioridade**, **Atendente responsável** e **Situação do prazo** (`No prazo`, `Vencendo` quando resta ≤ 20% do tempo, ou `Vencido`).
- A **Prioridade (1 a 6)** é calculada automaticamente pela matriz **Urgência (1..5) × Impacto (1..4)** definida no chamado.
- O painel da fila apresenta também a **média de satisfação** (1 a 5 estrelas) das avaliações registradas pelos solicitantes na área.
- Use **Atribuir a mim** para assumir o chamado imediatamente ou selecione outro membro da área.

### 2.2. Atendimento, pausa de SLA e solução
1. Na tela de detalhe do chamado, consulte os dados do solicitante, o beneficiário (quando aberto em nome de terceiro), as respostas do formulário dinâmico, os anexos e a **linha do tempo** completa.
2. **Acompanhamentos**:
   - **Comentário público**: visível ao solicitante e aos atendentes (gera notificação por e-mail para a outra parte);
   - **Nota interna**: visível exclusivamente para atendentes da área e Admin/TI (nunca gera e-mail ao solicitante).
3. **Status e relógio de SLA**:
   - Ao receber o primeiro atendimento/atribuição, o sistema registra o horário de resposta (`respondido_em`);
   - Quando o chamado depende de retorno do solicitante ou de fornecedor externo, altere o status para **Pendente**: o relógio do SLA de solução **pausa automaticamente** enquanto o chamado permanecer em `pendente`, retomando ao voltar para `atribuido` ou `planejado`;
   - Ao concluir a execução, registre a **Solução** (status muda para `solucionado`, grava `solucionado_em` e notifica o solicitante).

### 2.3. Aprovação, fechamento e pesquisa de satisfação
- Após solucionado, o solicitante pode **Aprovar a solução** (passando o chamado para `fechado`) ou **Reabrir** (retornando para `atribuido` caso a demanda não tenha sido sanada).
- Quando o chamado passa para **Fechado**, o solicitante pode registrar uma única **avaliação de satisfação de 1 a 5 estrelas** com comentário opcional, exibida para a equipe no detalhe do chamado e consolidada no indicador da fila.
