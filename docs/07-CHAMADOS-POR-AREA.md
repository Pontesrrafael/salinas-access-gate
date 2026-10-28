# Solicitação de Serviços e Chamados por Área

Data: 28/10/2026 · Salinas Gold Mineração LTDA · TI

Módulo de solicitação de serviços e acompanhamento de chamados internos (inspirado no fluxo ITIL/GLPI da Salinas), integrado ao padrão visual e de governança do **Salinas Access Gate**. Permite que áreas de suporte — como **SSMA**, **Facilities**, **Meio Ambiente**, **Suprimentos** e novas áreas cadastradas pelo Admin/TI — recebam, priorizem e solucionem demandas com controle de SLA e formulários personalizados sem necessidade de desenvolvimento.

---

## 1. Guia do Administrador (`chamados.configurar`)

O gerenciamento completo fica em **Gestão → Configuração de Chamados** (`/servicos/config`), restrito ao perfil **Admin/TI**. Todas as alterações exigem token CSRF e são registradas na trilha de auditoria (`audit_log`).

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

### 1.3. Categorias em árvore e prazos de SLA
Cada área possui sua própria árvore hierárquica de categorias e subcategorias (ex.: `Infraestrutura › Elétrica › Iluminação`):
1. No bloco **Nova categoria**, preencha:
   - **Nome da categoria**;
   - **Categoria pai**: selecione `— Raiz (sem categoria pai) —` para categoria de 1º nível ou escolha uma categoria existente da área para criar uma subcategoria;
   - **Urgência padrão** (`1 — Muito Baixa` a `5 — Muito Alta`, padrão `3 — Média`);
   - **SLA de resposta** e **SLA de solução**: escolha um dos atalhos rápidos (`15 min`, `30 min`, `1 h`, `2 h`, `4 h`, `8 h`, `24 h`, `72 h`) ou informe o tempo exato em minutos no campo numérico ao lado.
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
     - `Anexo` — validado no servidor via `App\Services\Upload`.
   - **Aplicar na categoria**: deixe em `— Todas as categorias da área —` para exibir o campo em qualquer chamado da área, ou escolha uma categoria específica;
   - **Opções**: uma opção por linha (obrigatório para `Lista` e `Múltipla escolha`);
   - **Texto de ajuda** e caixa **Preenchimento obrigatório**.
2. Na tabela de campos configurados, utilize os botões **↑** e **↓** para reordenar as perguntas, **Desativar/Ativar** para suspender ou reabilitar um campo sem perder o histórico, ou **Editar** para ajustar rótulo e opções.
3. As respostas de chamados já abertos são gravadas com cópia do rótulo do momento da abertura (`chamado_respostas`), preservando o histórico mesmo que o formulário seja alterado depois.

---

## 2. Guia para Atendentes e Gestores de Área (`chamados.atender`)

Usuários vinculados a pelo menos uma área de suporte (ou com perfil **Admin/TI**) acessam **Serviços → Fila de atendimento**:

### 2.1. Triagem e filtros da fila
- Filtre os chamados por **Área**, **Status**, **Categoria**, **Prioridade**, **Atendente responsável** e **Situação do prazo** (`No prazo`, `Vencendo` quando resta ≤ 20% do tempo, ou `Vencido`).
- A **Prioridade (1 a 6)** é calculada automaticamente pela matriz **Urgência (1..5) × Impacto (1..4)** definida no chamado.
- Use **Atribuir a mim** para assumir o chamado imediatamente ou selecione outro membro da área.

### 2.2. Atendimento, pausa de SLA e solução
1. Na tela de detalhe do chamado, consulte os dados do solicitante, o beneficiário (quando aberto em nome de terceiro), as respostas do formulário dinâmico, os anexos e a **linha do tempo** completa.
2. **Acompanhamentos**:
   - **Comentário público**: visível ao solicitante e aos atendentes;
   - **Nota interna**: visível exclusivamente para atendentes da área e Admin/TI.
3. **Status e relógio de SLA**:
   - Ao receber o primeiro atendimento/atribuição, o sistema registra o horário de resposta (`respondido_em`);
   - Quando o chamado depende de retorno do solicitante ou de fornecedor externo, altere o status para **Pendente**: o relógio do SLA de solução **pausa automaticamente** enquanto o chamado permanecer em `pendente`, somando o tempo pausado (`segundos_pendente`) ao retomar para `atribuido` ou `planejado`;
   - Ao concluir a execução, registre a **Solução** (status muda para `solucionado` e grava `solucionado_em`).

### 2.3. Aprovação e fechamento
- Após solucionado, o solicitante pode **Aprovar a solução** (passando o chamado para `fechado`) ou **Reabrir** (retornando para `atribuido` caso a demanda não tenha sido sanada).
- Chamados solucionados sem manifestação após o prazo configurado em `chamados_fechar_dias` (padrão: 7 dias) podem ser encerrados automaticamente.
