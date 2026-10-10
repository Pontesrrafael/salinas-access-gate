-- =====================================================================
-- Salinas Access Gate — Migração 004: banco documentado + camada de BI
-- Documento: MD-TI-SIS-CBA-006 (docs/06-BI-DICIONARIO-DE-DADOS.md)
-- Horário de Mato Grosso – UTC-4
--
-- PARTE 1  Comentários em todas as tabelas e colunas operacionais
--          (visíveis no DBeaver/HeidiSQL/Workbench e em information_schema).
--          Só muda metadados: tipos, nulos, padrões e CHECKs são idênticos aos das migrações 001/003.
-- PARTE 2  Views "bi_*" para consumo por BI (somente leitura, sem dados sensíveis).
-- PARTE 3  Tabela bi_dicionario com a descrição de cada view e coluna.
--
-- O usuário de consulta (sag_bi) NÃO é criado aqui: a aplicação não tem permissão de CREATE USER.
-- Ele é criado por docker/instalar.sh (Docker) ou pelo SQL de "php bin/console.php bi-sql" (DBA, como root).
-- =====================================================================
SET NAMES utf8mb4;

-- =====================================================================
-- PARTE 1 — Comentários (tabelas e colunas)
-- =====================================================================
-- pessoas
ALTER TABLE pessoas
  COMMENT = 'Cadastro de pessoas que passam pela portaria: colaboradores, visitantes e terceiros. Dados pessoais protegidos pela LGPD (CPF cifrado).',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `nome` varchar(200) NOT NULL COMMENT 'Nome completo.',
  MODIFY COLUMN `cpf_enc` varbinary(255) DEFAULT NULL COMMENT 'CPF cifrado com libsodium (secretbox). Ilegível sem a chave CPF_ENCRYPTION_KEY do app.env.',
  MODIFY COLUMN `cpf_hash` char(64) DEFAULT NULL COMMENT 'Índice cego do CPF (HMAC-SHA256) para busca e unicidade sem expor o número.',
  MODIFY COLUMN `rg` varchar(30) DEFAULT NULL COMMENT 'RG ou outro documento de identificação (dado pessoal).',
  MODIFY COLUMN `matricula` varchar(50) DEFAULT NULL COMMENT 'Matrícula do colaborador (RH).',
  MODIFY COLUMN `categoria` enum('colaborador','visitante','terceiro') NOT NULL COMMENT 'Categoria: colaborador, visitante ou terceiro.',
  MODIFY COLUMN `empresa` varchar(150) DEFAULT NULL COMMENT 'Empresa de origem (terceiros/visitantes) ou Salinas Gold.',
  MODIFY COLUMN `funcao` varchar(120) DEFAULT NULL COMMENT 'Cargo ou função.',
  MODIFY COLUMN `telefone` varchar(30) DEFAULT NULL COMMENT 'Telefone (campo legado do Lovable).',
  MODIFY COLUMN `telefone_principal` varchar(30) DEFAULT NULL COMMENT 'Telefone principal de contato (dado pessoal).',
  MODIFY COLUMN `email_principal` varchar(190) DEFAULT NULL COMMENT 'E-mail de contato (dado pessoal).',
  MODIFY COLUMN `whatsapp` varchar(30) DEFAULT NULL COMMENT 'WhatsApp de contato (dado pessoal).',
  MODIFY COLUMN `observacoes_contato` text DEFAULT NULL COMMENT 'Observações sobre contato.',
  MODIFY COLUMN `destino_padrao` varchar(150) DEFAULT NULL COMMENT 'Destino sugerido na portaria para esta pessoa.',
  MODIFY COLUMN `responsavel_interno_padrao` varchar(150) DEFAULT NULL COMMENT 'Responsável interno sugerido na portaria.',
  MODIFY COLUMN `observacoes_portaria_padrao` text DEFAULT NULL COMMENT 'Observação exibida ao porteiro ao registrar acesso.',
  MODIFY COLUMN `observacoes` text DEFAULT NULL COMMENT 'Observações gerais do cadastro.',
  MODIFY COLUMN `ativo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = cadastro ativo; 0 = inativo (não aparece na portaria).',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (Horário de Mato Grosso – UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- usuarios
ALTER TABLE usuarios
  COMMENT = 'Usuários que acessam o sistema (porteiros, SSMA, supervisores e administradores). Login local ou Active Directory.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `nome` varchar(150) NOT NULL COMMENT 'Nome do usuário.',
  MODIFY COLUMN `email` varchar(190) NOT NULL COMMENT 'E-mail corporativo (login; também aceita só a parte antes do @). Contas do AD sem e-mail recebem <login>@ad.local.',
  MODIFY COLUMN `senha_hash` varchar(255) NOT NULL COMMENT 'Hash bcrypt da senha local (contas AD não usam). Nunca expor.',
  MODIFY COLUMN `auth_origem` enum('local','ldap') NOT NULL DEFAULT 'local' COMMENT 'Origem da autenticação: local (senha no sistema) ou ldap (Active Directory).',
  MODIFY COLUMN `ldap_guid` varchar(64) DEFAULT NULL COMMENT 'objectGUID da conta no AD (vínculo estável mesmo se renomear).',
  MODIFY COLUMN `ldap_usuario` varchar(190) DEFAULT NULL COMMENT 'Login de rede (sAMAccountName) em minúsculas.',
  MODIFY COLUMN `ldap_dn` varchar(500) DEFAULT NULL COMMENT 'Distinguished Name da conta no AD na última sincronização.',
  MODIFY COLUMN `ldap_sincronizado_em` datetime DEFAULT NULL COMMENT 'Última sincronização com o AD (UTC-4).',
  MODIFY COLUMN `papel` enum('admin','supervisor','ssma','porteiro') NOT NULL COMMENT 'Perfil de acesso: admin, supervisor, ssma ou porteiro.',
  MODIFY COLUMN `matricula` varchar(50) DEFAULT NULL COMMENT 'Matrícula do colaborador.',
  MODIFY COLUMN `ativo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = pode entrar no sistema; 0 = bloqueado.',
  MODIFY COLUMN `must_change_password` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = deve trocar a senha no próximo login.',
  MODIFY COLUMN `seguranca_patrimonial` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = membro da Segurança Patrimonial (permissões complementares).',
  MODIFY COLUMN `pessoa_id` char(36) DEFAULT NULL COMMENT 'Pessoa vinculada (cadastro de portaria do próprio usuário), quando houver.',
  MODIFY COLUMN `ultimo_login_em` datetime DEFAULT NULL COMMENT 'Último login bem-sucedido (UTC-4).',
  MODIFY COLUMN `senha_alterada_em` datetime DEFAULT NULL COMMENT 'Última troca de senha local (UTC-4).',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- areas
ALTER TABLE areas
  COMMENT = 'Áreas da empresa que recebem visitantes e autorizam solicitações de acesso.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `nome` varchar(120) NOT NULL COMMENT 'Nome da área (único).',
  MODIFY COLUMN `responsavel_user_id` char(36) DEFAULT NULL COMMENT 'Usuário responsável por autorizar solicitações da área.',
  MODIFY COLUMN `ativo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = área ativa; 0 = inativa.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- locais
ALTER TABLE locais
  COMMENT = 'Locais com portaria (mina, escritório). Todo registro de acesso pertence a um local.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `nome` varchar(150) NOT NULL COMMENT 'Nome do local/portaria.',
  MODIFY COLUMN `tipo` enum('mina','escritorio') NOT NULL COMMENT 'Tipo do local: mina ou escritorio.',
  MODIFY COLUMN `endereco` varchar(255) DEFAULT NULL COMMENT 'Endereço do local.',
  MODIFY COLUMN `ativo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = local ativo; 0 = inativo.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- veiculos
ALTER TABLE veiculos
  COMMENT = 'Veículos cadastrados (próprios, de terceiros e de visitantes).',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `placa` varchar(10) NOT NULL COMMENT 'Placa (única), em maiúsculas e sem hífen.',
  MODIFY COLUMN `tipo` varchar(30) NOT NULL DEFAULT 'carro' COMMENT 'Tipo: carro, caminhonete, caminhão, moto, ônibus, máquina etc.',
  MODIFY COLUMN `marca_modelo` varchar(120) DEFAULT NULL COMMENT 'Marca e modelo.',
  MODIFY COLUMN `cor` varchar(40) DEFAULT NULL COMMENT 'Cor.',
  MODIFY COLUMN `empresa` varchar(150) DEFAULT NULL COMMENT 'Empresa proprietária ou responsável.',
  MODIFY COLUMN `tag` varchar(60) DEFAULT NULL COMMENT 'Tag/cartão de acesso veicular (única).',
  MODIFY COLUMN `comissionado_mina` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = veículo comissionado para circular na mina.',
  MODIFY COLUMN `comissionamento_validade` date DEFAULT NULL COMMENT 'Validade do comissionamento para a mina.',
  MODIFY COLUMN `observacoes` text DEFAULT NULL COMMENT 'Observações.',
  MODIFY COLUMN `ativo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = veículo ativo; 0 = inativo.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- documentos
ALTER TABLE documentos
  COMMENT = 'Documentos de SSMA da pessoa com validade (ASO, NR-22, NR-35, NR-33, integração, CNH).',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `pessoa_id` char(36) NOT NULL COMMENT 'Pessoa dona do documento.',
  MODIFY COLUMN `tipo` enum('aso','nr22','nr35','nr33','integracao','cnh','outro') NOT NULL COMMENT 'Tipo: aso, nr22, nr35, nr33, integracao, cnh ou outro.',
  MODIFY COLUMN `numero` varchar(60) DEFAULT NULL COMMENT 'Número do documento/certificado.',
  MODIFY COLUMN `emissao` date DEFAULT NULL COMMENT 'Data de emissão.',
  MODIFY COLUMN `validade` date NOT NULL COMMENT 'Data de validade (vencido quando menor que hoje).',
  MODIFY COLUMN `observacoes` text DEFAULT NULL COMMENT 'Observações.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- solicitacoes_acesso
ALTER TABLE solicitacoes_acesso
  COMMENT = 'Solicitações de acesso de visitantes e terceiros: pedido, autorização da área, liberação SSMA e realização.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `visitante_nome` varchar(200) NOT NULL COMMENT 'Nome do visitante/terceiro informado na solicitação.',
  MODIFY COLUMN `visitante_doc` varchar(30) DEFAULT NULL COMMENT 'Documento informado na solicitação (dado pessoal).',
  MODIFY COLUMN `empresa` varchar(150) DEFAULT NULL COMMENT 'Empresa do visitante/terceiro.',
  MODIFY COLUMN `contato_telefone` varchar(30) DEFAULT NULL COMMENT 'Telefone de contato (dado pessoal).',
  MODIFY COLUMN `contato_email` varchar(190) DEFAULT NULL COMMENT 'E-mail de contato (dado pessoal).',
  MODIFY COLUMN `tipo` enum('visitante','terceiro') NOT NULL DEFAULT 'visitante' COMMENT 'Tipo: visitante ou terceiro.',
  MODIFY COLUMN `area_id` char(36) DEFAULT NULL COMMENT 'Área visitada (quem autoriza).',
  MODIFY COLUMN `responsavel_interno_id` char(36) DEFAULT NULL COMMENT 'Usuário anfitrião/responsável interno.',
  MODIFY COLUMN `pessoa_id` char(36) DEFAULT NULL COMMENT 'Pessoa cadastrada vinculada (preenchido ao aprovar ou na portaria).',
  MODIFY COLUMN `local_id` char(36) DEFAULT NULL COMMENT 'Local/portaria de entrada previsto.',
  MODIFY COLUMN `data_prevista` date NOT NULL COMMENT 'Data prevista da visita.',
  MODIFY COLUMN `motivo` text DEFAULT NULL COMMENT 'Motivo da visita.',
  MODIFY COLUMN `atividade_risco` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = atividade de risco: exige verificação SSMA antes da aprovação.',
  MODIFY COLUMN `status` enum('aguardando_ssma','pendente','aprovada','recusada','realizada','expirada') NOT NULL DEFAULT 'pendente' COMMENT 'Situação: aguardando_ssma, pendente, aprovada, recusada, realizada ou expirada.',
  MODIFY COLUMN `status_observacao` text DEFAULT NULL COMMENT 'Observação da última mudança de situação.',
  MODIFY COLUMN `origem` enum('interna','link','publica') NOT NULL DEFAULT 'interna' COMMENT 'Origem: interna (usuário), link (link externo enviado) ou publica (formulário público).',
  MODIFY COLUMN `token_externo` varchar(64) DEFAULT NULL COMMENT 'Token do link externo de preenchimento (segredo; nunca expor).',
  MODIFY COLUMN `link_expira_em` datetime DEFAULT NULL COMMENT 'Expiração do link externo (UTC-4).',
  MODIFY COLUMN `aguardando_preenchimento` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = link enviado e ainda não preenchido pelo visitante.',
  MODIFY COLUMN `autorizado_por` char(36) DEFAULT NULL COMMENT 'Usuário que aprovou ou recusou.',
  MODIFY COLUMN `autorizado_em` datetime DEFAULT NULL COMMENT 'Data/hora da decisão (UTC-4).',
  MODIFY COLUMN `motivo_recusa` text DEFAULT NULL COMMENT 'Motivo da recusa.',
  MODIFY COLUMN `consentimento_lgpd` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = visitante aceitou o aviso de privacidade (LGPD).',
  MODIFY COLUMN `criado_por` char(36) DEFAULT NULL COMMENT 'Usuário que criou (nulo quando veio do formulário público/link).',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de criação (UTC-4).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Data/hora da última alteração (UTC-4).';
-- verificacoes_ssma
ALTER TABLE verificacoes_ssma
  COMMENT = 'Histórico imutável das verificações de SSMA (apto/não apto) feitas nas solicitações de risco.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `solicitacao_id` char(36) NOT NULL COMMENT 'Solicitação verificada.',
  MODIFY COLUMN `pessoa_id` char(36) DEFAULT NULL COMMENT 'Pessoa verificada, quando vinculada.',
  MODIFY COLUMN `status` enum('apto','nao_apto') NOT NULL COMMENT 'Resultado: apto ou nao_apto.',
  MODIFY COLUMN `observacao` text DEFAULT NULL COMMENT 'Observação do técnico de SSMA.',
  MODIFY COLUMN `checklist` longtext NOT NULL COMMENT 'Checklist em JSON (itens conferidos e resultado de cada um).' CHECK (json_valid(`checklist`)),
  MODIFY COLUMN `validade_ate` date DEFAULT NULL COMMENT 'Validade da liberação SSMA.',
  MODIFY COLUMN `verificado_por` char(36) DEFAULT NULL COMMENT 'Usuário de SSMA que verificou.',
  MODIFY COLUMN `verificado_por_nome` varchar(150) DEFAULT NULL COMMENT 'Nome do verificador gravado no momento (histórico).',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora da verificação (UTC-4).';
-- registros_acesso
ALTER TABLE registros_acesso
  COMMENT = 'Movimentações de portaria (entradas e saídas). Append-only: triggers bloqueiam UPDATE/DELETE e cada INSERT gera auditoria.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `seq` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Sequência física de inserção (desempate quando data_hora é igual).',
  MODIFY COLUMN `pessoa_id` char(36) DEFAULT NULL COMMENT 'Pessoa que entrou/saiu (nulo quando só veículo).',
  MODIFY COLUMN `veiculo_id` char(36) DEFAULT NULL COMMENT 'Veículo que entrou/saiu, quando houver.',
  MODIFY COLUMN `local_id` char(36) NOT NULL COMMENT 'Local/portaria do registro.',
  MODIFY COLUMN `solicitacao_id` char(36) DEFAULT NULL COMMENT 'Solicitação de acesso atendida, quando houver.',
  MODIFY COLUMN `sentido` enum('entrada','saida') NOT NULL COMMENT 'Sentido: entrada ou saida.',
  MODIFY COLUMN `data_hora` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora do movimento (Horário de Mato Grosso – UTC-4).',
  MODIFY COLUMN `destino` varchar(150) DEFAULT NULL COMMENT 'Destino informado na entrada.',
  MODIFY COLUMN `responsavel_interno` varchar(150) DEFAULT NULL COMMENT 'Responsável interno (texto livre legado).',
  MODIFY COLUMN `responsavel_interno_id` char(36) DEFAULT NULL COMMENT 'Pessoa responsável interna (anfitrião).',
  MODIFY COLUMN `responsavel_interno_nome` varchar(150) DEFAULT NULL COMMENT 'Nome do responsável interno gravado no momento.',
  MODIFY COLUMN `responsavel_interno_telefone` varchar(30) DEFAULT NULL COMMENT 'Telefone do responsável interno (dado pessoal).',
  MODIFY COLUMN `responsavel_interno_email` varchar(190) DEFAULT NULL COMMENT 'E-mail do responsável interno.',
  MODIFY COLUMN `porteiro_id` char(36) NOT NULL COMMENT 'Usuário que registrou o movimento.',
  MODIFY COLUMN `observacoes` text DEFAULT NULL COMMENT 'Observações do porteiro.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora de gravação no banco (UTC-4).';
-- registro_anexos
ALTER TABLE registro_anexos
  COMMENT = 'Fotos e anexos dos registros de acesso (append-only). Arquivos ficam em storage/uploads.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `registro_acesso_id` char(36) NOT NULL COMMENT 'Registro de acesso ao qual o anexo pertence.',
  MODIFY COLUMN `storage_path` varchar(255) NOT NULL COMMENT 'Caminho relativo do arquivo em storage/uploads.',
  MODIFY COLUMN `tipo_foto` enum('pessoa','veiculo','placa','documento','outra') NOT NULL DEFAULT 'outra' COMMENT 'Tipo: pessoa, veiculo, placa, documento ou outra.',
  MODIFY COLUMN `nome_arquivo` varchar(255) DEFAULT NULL COMMENT 'Nome original do arquivo.',
  MODIFY COLUMN `mime_type` varchar(60) DEFAULT NULL COMMENT 'Tipo MIME (image/jpeg, image/webp...).',
  MODIFY COLUMN `tamanho_bytes` bigint(20) DEFAULT NULL COMMENT 'Tamanho em bytes.',
  MODIFY COLUMN `sha256` char(64) DEFAULT NULL COMMENT 'Hash SHA-256 do arquivo (integridade).',
  MODIFY COLUMN `criado_por` char(36) DEFAULT NULL COMMENT 'Usuário que anexou.',
  MODIFY COLUMN `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora do anexo (UTC-4).';
-- audit_log
ALTER TABLE audit_log
  COMMENT = 'Trilha de auditoria (append-only): quem fez o quê, quando e de onde.',
  MODIFY COLUMN `id` char(36) NOT NULL COMMENT 'Identificador único (UUID v4).',
  MODIFY COLUMN `seq` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Sequência de inserção.',
  MODIFY COLUMN `entidade` varchar(60) NOT NULL COMMENT 'Tabela/assunto auditado (ex.: registros_acesso, usuarios, configuracoes).',
  MODIFY COLUMN `registro_id` varchar(64) DEFAULT NULL COMMENT 'Identificador do registro afetado.',
  MODIFY COLUMN `acao` varchar(60) NOT NULL COMMENT 'Ação (INSERT, UPDATE, LOGIN, LDAP_CONFIG...).',
  MODIFY COLUMN `usuario_id` char(36) DEFAULT NULL COMMENT 'Usuário que executou (nulo para processos automáticos).',
  MODIFY COLUMN `detalhes` longtext DEFAULT NULL COMMENT 'Detalhes em JSON (sem senhas).' CHECK (`detalhes` is null or json_valid(`detalhes`)),
  MODIFY COLUMN `ip` varchar(45) DEFAULT NULL COMMENT 'IP de origem (ou cli).',
  MODIFY COLUMN `data_hora` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora do evento (UTC-4).';
-- configuracoes
ALTER TABLE configuracoes
  COMMENT = 'Parâmetros do sistema em chave/valor (inclui ldap.* configurados pela tela).',
  MODIFY COLUMN `chave` varchar(80) NOT NULL COMMENT 'Nome do parâmetro (ex.: alerta_permanencia_horas, ldap.enabled).',
  MODIFY COLUMN `valor` text DEFAULT NULL COMMENT 'Valor do parâmetro (texto). Segredos ficam cifrados (sufixo _enc).',
  MODIFY COLUMN `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Última alteração (UTC-4).',
  MODIFY COLUMN `updated_by` char(36) DEFAULT NULL COMMENT 'Usuário que alterou.';
-- rate_limits
ALTER TABLE rate_limits
  COMMENT = 'Controle de tentativas (login e formulário público) contra força bruta. Dados temporários.',
  MODIFY COLUMN `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Identificador sequencial.',
  MODIFY COLUMN `chave` varchar(190) NOT NULL COMMENT 'Chave da tentativa (ação + IP/usuário).',
  MODIFY COLUMN `criado_em` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Data/hora da tentativa (UTC-4).';

-- =====================================================================
-- PARTE 2 — Camada de BI (views "bi_*")
-- Regras:
--   * Somente leitura, prontas para Power BI / Metabase / Excel.
--   * Sem dados sensíveis: nada de CPF, RG, telefone, e-mail, tokens ou hashes (LGPD, minimização).
--   * Datas/horas no Horário de Mato Grosso – UTC-4. "Agora" é calculado por
--     CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00'), que não depende do fuso da sessão do BI.
--   * Chaves *_id são UUID (texto de 36). Relacione fatos e dimensões por elas.
--   * dia_semana: 1 = segunda ... 7 = domingo (ISO 8601).
-- =====================================================================

-- ---------------------------- Dimensões ------------------------------

CREATE OR REPLACE VIEW bi_dim_local AS
SELECT l.id    AS local_id,
       l.nome  AS local_nome,
       l.tipo  AS local_tipo,
       l.ativo AS ativo
  FROM locais l;

CREATE OR REPLACE VIEW bi_dim_area AS
SELECT a.id                  AS area_id,
       a.nome                AS area_nome,
       a.responsavel_user_id AS responsavel_usuario_id,
       u.nome                AS responsavel_nome,
       a.ativo               AS ativo
  FROM areas a
  LEFT JOIN usuarios u ON u.id = a.responsavel_user_id;

CREATE OR REPLACE VIEW bi_dim_pessoa AS
SELECT p.id                      AS pessoa_id,
       p.nome                    AS pessoa_nome,
       p.categoria               AS categoria,
       p.empresa                 AS empresa,
       p.funcao                  AS funcao,
       p.matricula               AS matricula,
       (p.cpf_hash IS NOT NULL)  AS possui_cpf,
       p.ativo                   AS ativo,
       DATE(p.created_at)        AS cadastrado_em
  FROM pessoas p;

CREATE OR REPLACE VIEW bi_dim_veiculo AS
SELECT v.id                       AS veiculo_id,
       v.placa                    AS placa,
       v.tipo                     AS tipo,
       v.marca_modelo             AS marca_modelo,
       v.cor                      AS cor,
       v.empresa                  AS empresa,
       v.comissionado_mina        AS comissionado_mina,
       v.comissionamento_validade AS comissionamento_validade,
       (v.comissionado_mina = 1
         AND (v.comissionamento_validade IS NULL
              OR v.comissionamento_validade >= DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00')))) AS comissionamento_vigente,
       v.ativo                    AS ativo
  FROM veiculos v;

CREATE OR REPLACE VIEW bi_dim_usuario AS
SELECT u.id                    AS usuario_id,
       u.nome                  AS usuario_nome,
       u.papel                 AS papel,
       u.seguranca_patrimonial AS seguranca_patrimonial,
       u.auth_origem           AS auth_origem,
       u.ativo                 AS ativo,
       u.ultimo_login_em       AS ultimo_login_em
  FROM usuarios u;

-- ------------------------------ Fatos --------------------------------

-- Uma linha por movimento de portaria (entrada ou saída)
CREATE OR REPLACE VIEW bi_fato_movimentacao AS
SELECT r.id                       AS registro_id,
       r.seq                      AS seq,
       r.data_hora                AS data_hora,
       DATE(r.data_hora)          AS data,
       HOUR(r.data_hora)          AS hora,
       WEEKDAY(r.data_hora) + 1   AS dia_semana,
       ELT(WEEKDAY(r.data_hora) + 1, 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo') AS dia_semana_nome,
       r.sentido                  AS sentido,
       r.local_id                 AS local_id,
       l.nome                     AS local_nome,
       r.pessoa_id                AS pessoa_id,
       p.nome                     AS pessoa_nome,
       p.categoria                AS pessoa_categoria,
       p.empresa                  AS pessoa_empresa,
       r.veiculo_id               AS veiculo_id,
       v.placa                    AS veiculo_placa,
       r.solicitacao_id           AS solicitacao_id,
       r.destino                  AS destino,
       r.responsavel_interno_nome AS responsavel_interno_nome,
       r.porteiro_id              AS porteiro_id,
       u.nome                     AS porteiro_nome,
       (SELECT COUNT(*) FROM registro_anexos a WHERE a.registro_acesso_id = r.id) AS qtd_fotos
  FROM registros_acesso r
  JOIN locais l        ON l.id = r.local_id
  LEFT JOIN pessoas p  ON p.id = r.pessoa_id
  LEFT JOIN veiculos v ON v.id = r.veiculo_id
  LEFT JOIN usuarios u ON u.id = r.porteiro_id;

-- Uma linha por ENTRADA de pessoa, pareada com a saída seguinte no mesmo local
CREATE OR REPLACE VIEW bi_fato_permanencia AS
SELECT m.id                 AS registro_entrada_id,
       CASE WHEN m.prox_sentido = 'saida' THEN m.prox_id END AS registro_saida_id,
       m.pessoa_id          AS pessoa_id,
       p.nome               AS pessoa_nome,
       p.categoria          AS pessoa_categoria,
       p.empresa            AS pessoa_empresa,
       m.local_id           AS local_id,
       l.nome               AS local_nome,
       m.veiculo_id         AS veiculo_id,
       m.data_hora          AS entrada_em,
       DATE(m.data_hora)    AS data_entrada,
       CASE WHEN m.prox_sentido = 'saida' THEN m.prox_data_hora END AS saida_em,
       CASE WHEN m.prox_sentido = 'saida' THEN 'concluida'
            WHEN m.prox_sentido IS NULL   THEN 'em_andamento'
            ELSE 'sem_saida_registrada' END AS situacao,
       CASE WHEN m.prox_sentido = 'saida' THEN TIMESTAMPDIFF(MINUTE, m.data_hora, m.prox_data_hora)
            WHEN m.prox_sentido IS NULL   THEN TIMESTAMPDIFF(MINUTE, m.data_hora, CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00'))
       END AS permanencia_min
  FROM (
        SELECT r.id, r.pessoa_id, r.local_id, r.veiculo_id, r.sentido, r.data_hora,
               LEAD(r.sentido)   OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora, r.seq) AS prox_sentido,
               LEAD(r.data_hora) OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora, r.seq) AS prox_data_hora,
               LEAD(r.id)        OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora, r.seq) AS prox_id
          FROM registros_acesso r
         WHERE r.pessoa_id IS NOT NULL
       ) m
  JOIN pessoas p ON p.id = m.pessoa_id
  JOIN locais  l ON l.id = m.local_id
 WHERE m.sentido = 'entrada';

-- Quem está dentro agora (mesma regra da tela "Quem está no site")
CREATE OR REPLACE VIEW bi_presenca_atual AS
SELECT pr.registro_id      AS registro_id,
       pr.pessoa_id        AS pessoa_id,
       pr.pessoa_nome      AS pessoa_nome,
       pr.pessoa_categoria AS pessoa_categoria,
       pr.pessoa_empresa   AS pessoa_empresa,
       pr.veiculo_id       AS veiculo_id,
       pr.veiculo_placa    AS veiculo_placa,
       pr.local_id         AS local_id,
       pr.local_nome       AS local_nome,
       pr.local_tipo       AS local_tipo,
       pr.destino          AS destino,
       pr.entrada_em       AS entrada_em,
       TIMESTAMPDIFF(MINUTE, pr.entrada_em, CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00')) AS minutos_dentro,
       (TIMESTAMPDIFF(MINUTE, pr.entrada_em, CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00'))
          > 60 * COALESCE((SELECT CAST(c.valor AS UNSIGNED) FROM configuracoes c WHERE c.chave = 'alerta_permanencia_horas'), 14)) AS acima_alerta_permanencia
  FROM vw_presenca_dentro pr;

-- Uma linha por solicitação de acesso (sem documento/contato do visitante nem token)
CREATE OR REPLACE VIEW bi_fato_solicitacao AS
SELECT s.id                     AS solicitacao_id,
       s.created_at             AS criada_em,
       DATE(s.created_at)       AS data_criacao,
       s.data_prevista          AS data_prevista,
       s.tipo                   AS tipo,
       s.status                 AS status,
       s.origem                 AS origem,
       s.atividade_risco        AS atividade_risco,
       s.area_id                AS area_id,
       a.nome                   AS area_nome,
       s.local_id               AS local_id,
       l.nome                   AS local_nome,
       s.empresa                AS empresa,
       s.visitante_nome         AS visitante_nome,
       s.pessoa_id              AS pessoa_id,
       s.responsavel_interno_id AS responsavel_interno_id,
       ru.nome                  AS responsavel_interno_nome,
       s.autorizado_por         AS decidido_por_id,
       au.nome                  AS decidido_por_nome,
       s.autorizado_em          AS decidido_em,
       TIMESTAMPDIFF(MINUTE, s.created_at, s.autorizado_em) AS minutos_ate_decisao,
       s.consentimento_lgpd     AS consentimento_lgpd,
       (SELECT COUNT(*) FROM registros_acesso r WHERE r.solicitacao_id = s.id AND r.sentido = 'entrada') AS qtd_entradas
  FROM solicitacoes_acesso s
  LEFT JOIN areas a     ON a.id = s.area_id
  LEFT JOIN locais l    ON l.id = s.local_id
  LEFT JOIN usuarios ru ON ru.id = s.responsavel_interno_id
  LEFT JOIN usuarios au ON au.id = s.autorizado_por;

-- Uma linha por verificação de SSMA (histórico imutável)
CREATE OR REPLACE VIEW bi_fato_verificacao_ssma AS
SELECT v.id                  AS verificacao_id,
       v.solicitacao_id      AS solicitacao_id,
       v.pessoa_id           AS pessoa_id,
       p.nome                AS pessoa_nome,
       v.status              AS status,
       v.validade_ate        AS validade_ate,
       v.verificado_por      AS verificador_id,
       v.verificado_por_nome AS verificador_nome,
       v.created_at          AS verificado_em,
       DATE(v.created_at)    AS data_verificacao,
       JSON_LENGTH(v.checklist) AS itens_checklist,
       (LENGTH(v.checklist) - LENGTH(REPLACE(v.checklist, 'true', ''))) DIV 4 AS itens_conformes
  FROM verificacoes_ssma v
  LEFT JOIN pessoas p ON p.id = v.pessoa_id;

-- Uma linha por documento de SSMA, com situação calculada para hoje
CREATE OR REPLACE VIEW bi_fato_documento AS
SELECT d.documento_id, d.pessoa_id, p.nome AS pessoa_nome, p.categoria AS pessoa_categoria, p.empresa AS pessoa_empresa,
       p.ativo AS pessoa_ativa, d.tipo, d.emissao, d.validade,
       DATEDIFF(d.validade, d.hoje) AS dias_para_vencer,
       CASE WHEN d.validade < d.hoje THEN 'vencido'
            WHEN d.validade <= d.hoje + INTERVAL 30 DAY THEN 'vence_em_30_dias'
            ELSE 'valido' END AS situacao,
       (d.ordem = 1) AS mais_recente_do_tipo
  FROM (
        SELECT x.id AS documento_id, x.pessoa_id, x.tipo, x.emissao, x.validade,
               DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00')) AS hoje,
               ROW_NUMBER() OVER (PARTITION BY x.pessoa_id, x.tipo ORDER BY x.validade DESC, x.created_at DESC) AS ordem
          FROM documentos x
       ) d
  JOIN pessoas p ON p.id = d.pessoa_id;

-- --------------------------- Agregados -------------------------------

-- Resumo diário por local (carga leve para painéis)
CREATE OR REPLACE VIEW bi_agg_diario_local AS
SELECT DATE(r.data_hora)                                       AS data,
       r.local_id                                              AS local_id,
       l.nome                                                  AS local_nome,
       SUM(r.sentido = 'entrada')                              AS entradas,
       SUM(r.sentido = 'saida')                                AS saidas,
       SUM(r.sentido = 'entrada' AND p.categoria <=> 'colaborador') AS entradas_colaboradores,
       SUM(r.sentido = 'entrada' AND p.categoria <=> 'visitante')  AS entradas_visitantes,
       SUM(r.sentido = 'entrada' AND p.categoria <=> 'terceiro')  AS entradas_terceiros,
       COUNT(DISTINCT CASE WHEN r.sentido = 'entrada' THEN r.pessoa_id END)  AS pessoas_distintas,
       COUNT(DISTINCT CASE WHEN r.sentido = 'entrada' THEN r.veiculo_id END) AS veiculos_distintos
  FROM registros_acesso r
  JOIN locais l       ON l.id = r.local_id
  LEFT JOIN pessoas p ON p.id = r.pessoa_id
 GROUP BY DATE(r.data_hora), r.local_id, l.nome;

-- =====================================================================
-- PARTE 3 — Dicionário de dados (consultável pelo próprio BI)
-- =====================================================================
CREATE TABLE IF NOT EXISTS bi_dicionario (
  objeto        VARCHAR(64)   NOT NULL COMMENT 'Nome da view/tabela de BI.',
  coluna        VARCHAR(64)   NOT NULL COMMENT 'Nome da coluna ("*" = descrição do objeto).',
  ordem         INT           NOT NULL COMMENT 'Posição da coluna (0 = objeto).',
  tipo          VARCHAR(20)   NOT NULL COMMENT 'Tipo de dado em linguagem de negócio (texto, inteiro, data, data/hora, uuid).',
  descricao     VARCHAR(500)  NOT NULL COMMENT 'Descrição da coluna/objeto.',
  granularidade VARCHAR(200)  NULL     COMMENT 'O que representa cada linha (somente na linha do objeto).',
  PRIMARY KEY (objeto, coluna)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dicionário de dados da camada de BI (views bi_*). Regerado a cada migração de BI.';

DELETE FROM bi_dicionario;
INSERT INTO bi_dicionario (objeto, coluna, ordem, tipo, descricao, granularidade) VALUES
('bi_dim_local', '*', 0, 'view', 'Dimensão de locais/portarias.', 'Uma linha por local'),
('bi_dim_local', 'local_id', 1, 'uuid', 'Chave do local (UUID).', NULL),
('bi_dim_local', 'local_nome', 2, 'texto', 'Nome do local/portaria.', NULL),
('bi_dim_local', 'local_tipo', 3, 'texto', 'mina ou escritorio.', NULL),
('bi_dim_local', 'ativo', 4, 'inteiro', '1 = ativo.', NULL),
('bi_dim_area', '*', 0, 'view', 'Dimensão de áreas e seus responsáveis.', 'Uma linha por área'),
('bi_dim_area', 'area_id', 1, 'uuid', 'Chave da área (UUID).', NULL),
('bi_dim_area', 'area_nome', 2, 'texto', 'Nome da área.', NULL),
('bi_dim_area', 'responsavel_usuario_id', 3, 'uuid', 'Usuário responsável (liga em bi_dim_usuario).', NULL),
('bi_dim_area', 'responsavel_nome', 4, 'texto', 'Nome do responsável.', NULL),
('bi_dim_area', 'ativo', 5, 'inteiro', '1 = ativa.', NULL),
('bi_dim_pessoa', '*', 0, 'view', 'Dimensão de pessoas, sem dados sensíveis (sem CPF, RG, telefone ou e-mail).', 'Uma linha por pessoa'),
('bi_dim_pessoa', 'pessoa_id', 1, 'uuid', 'Chave da pessoa (UUID).', NULL),
('bi_dim_pessoa', 'pessoa_nome', 2, 'texto', 'Nome completo.', NULL),
('bi_dim_pessoa', 'categoria', 3, 'texto', 'colaborador, visitante ou terceiro.', NULL),
('bi_dim_pessoa', 'empresa', 4, 'texto', 'Empresa.', NULL),
('bi_dim_pessoa', 'funcao', 5, 'texto', 'Cargo/função.', NULL),
('bi_dim_pessoa', 'matricula', 6, 'texto', 'Matrícula (colaboradores).', NULL),
('bi_dim_pessoa', 'possui_cpf', 7, 'inteiro', '1 = CPF cadastrado (o número não é exposto).', NULL),
('bi_dim_pessoa', 'ativo', 8, 'inteiro', '1 = cadastro ativo.', NULL),
('bi_dim_pessoa', 'cadastrado_em', 9, 'data', 'Data do cadastro.', NULL),
('bi_dim_veiculo', '*', 0, 'view', 'Dimensão de veículos.', 'Uma linha por veículo'),
('bi_dim_veiculo', 'veiculo_id', 1, 'uuid', 'Chave do veículo (UUID).', NULL),
('bi_dim_veiculo', 'placa', 2, 'texto', 'Placa sem hífen.', NULL),
('bi_dim_veiculo', 'tipo', 3, 'texto', 'Tipo do veículo.', NULL),
('bi_dim_veiculo', 'marca_modelo', 4, 'texto', 'Marca e modelo.', NULL),
('bi_dim_veiculo', 'cor', 5, 'texto', 'Cor.', NULL),
('bi_dim_veiculo', 'empresa', 6, 'texto', 'Empresa responsável.', NULL),
('bi_dim_veiculo', 'comissionado_mina', 7, 'inteiro', '1 = comissionado para a mina.', NULL),
('bi_dim_veiculo', 'comissionamento_validade', 8, 'data', 'Validade do comissionamento.', NULL),
('bi_dim_veiculo', 'comissionamento_vigente', 9, 'inteiro', '1 = comissionado e dentro da validade hoje (UTC-4).', NULL),
('bi_dim_veiculo', 'ativo', 10, 'inteiro', '1 = ativo.', NULL),
('bi_dim_usuario', '*', 0, 'view', 'Dimensão de usuários do sistema (porteiros, SSMA, supervisores, admins), sem e-mail e sem senha.', 'Uma linha por usuário'),
('bi_dim_usuario', 'usuario_id', 1, 'uuid', 'Chave do usuário (UUID).', NULL),
('bi_dim_usuario', 'usuario_nome', 2, 'texto', 'Nome.', NULL),
('bi_dim_usuario', 'papel', 3, 'texto', 'admin, supervisor, ssma ou porteiro.', NULL),
('bi_dim_usuario', 'seguranca_patrimonial', 4, 'inteiro', '1 = Segurança Patrimonial.', NULL),
('bi_dim_usuario', 'auth_origem', 5, 'texto', 'local ou ldap (Active Directory).', NULL),
('bi_dim_usuario', 'ativo', 6, 'inteiro', '1 = ativo.', NULL),
('bi_dim_usuario', 'ultimo_login_em', 7, 'data/hora', 'Último login (UTC-4).', NULL),
('bi_fato_movimentacao', '*', 0, 'view', 'Fato principal: movimentos de portaria (entradas e saídas).', 'Uma linha por movimento'),
('bi_fato_movimentacao', 'registro_id', 1, 'uuid', 'Chave do movimento (UUID).', NULL),
('bi_fato_movimentacao', 'seq', 2, 'inteiro', 'Ordem de gravação (desempate).', NULL),
('bi_fato_movimentacao', 'data_hora', 3, 'data/hora', 'Data/hora do movimento (UTC-4).', NULL),
('bi_fato_movimentacao', 'data', 4, 'data', 'Data do movimento (liga no calendário do BI).', NULL),
('bi_fato_movimentacao', 'hora', 5, 'inteiro', 'Hora cheia (0–23).', NULL),
('bi_fato_movimentacao', 'dia_semana', 6, 'inteiro', '1 = segunda ... 7 = domingo.', NULL),
('bi_fato_movimentacao', 'dia_semana_nome', 7, 'texto', 'Nome do dia da semana.', NULL),
('bi_fato_movimentacao', 'sentido', 8, 'texto', 'entrada ou saida.', NULL),
('bi_fato_movimentacao', 'local_id', 9, 'uuid', 'Local (bi_dim_local).', NULL),
('bi_fato_movimentacao', 'local_nome', 10, 'texto', 'Nome do local.', NULL),
('bi_fato_movimentacao', 'pessoa_id', 11, 'uuid', 'Pessoa (bi_dim_pessoa); nulo quando só veículo.', NULL),
('bi_fato_movimentacao', 'pessoa_nome', 12, 'texto', 'Nome da pessoa.', NULL),
('bi_fato_movimentacao', 'pessoa_categoria', 13, 'texto', 'Categoria da pessoa.', NULL),
('bi_fato_movimentacao', 'pessoa_empresa', 14, 'texto', 'Empresa da pessoa.', NULL),
('bi_fato_movimentacao', 'veiculo_id', 15, 'uuid', 'Veículo (bi_dim_veiculo), quando houver.', NULL),
('bi_fato_movimentacao', 'veiculo_placa', 16, 'texto', 'Placa do veículo.', NULL),
('bi_fato_movimentacao', 'solicitacao_id', 17, 'uuid', 'Solicitação atendida (bi_fato_solicitacao), quando houver.', NULL),
('bi_fato_movimentacao', 'destino', 18, 'texto', 'Destino informado.', NULL),
('bi_fato_movimentacao', 'responsavel_interno_nome', 19, 'texto', 'Responsável interno (anfitrião).', NULL),
('bi_fato_movimentacao', 'porteiro_id', 20, 'uuid', 'Usuário que registrou (bi_dim_usuario).', NULL),
('bi_fato_movimentacao', 'porteiro_nome', 21, 'texto', 'Nome de quem registrou.', NULL),
('bi_fato_movimentacao', 'qtd_fotos', 22, 'inteiro', 'Quantidade de fotos/anexos do movimento.', NULL),
('bi_fato_permanencia', '*', 0, 'view', 'Permanência: cada entrada de pessoa pareada com a saída seguinte no mesmo local.', 'Uma linha por entrada de pessoa'),
('bi_fato_permanencia', 'registro_entrada_id', 1, 'uuid', 'Movimento de entrada.', NULL),
('bi_fato_permanencia', 'registro_saida_id', 2, 'uuid', 'Movimento de saída pareado (nulo se não houver).', NULL),
('bi_fato_permanencia', 'pessoa_id', 3, 'uuid', 'Pessoa.', NULL),
('bi_fato_permanencia', 'pessoa_nome', 4, 'texto', 'Nome.', NULL),
('bi_fato_permanencia', 'pessoa_categoria', 5, 'texto', 'Categoria.', NULL),
('bi_fato_permanencia', 'pessoa_empresa', 6, 'texto', 'Empresa.', NULL),
('bi_fato_permanencia', 'local_id', 7, 'uuid', 'Local.', NULL),
('bi_fato_permanencia', 'local_nome', 8, 'texto', 'Nome do local.', NULL),
('bi_fato_permanencia', 'veiculo_id', 9, 'uuid', 'Veículo usado na entrada.', NULL),
('bi_fato_permanencia', 'entrada_em', 10, 'data/hora', 'Data/hora da entrada (UTC-4).', NULL),
('bi_fato_permanencia', 'data_entrada', 11, 'data', 'Data da entrada.', NULL),
('bi_fato_permanencia', 'saida_em', 12, 'data/hora', 'Data/hora da saída (UTC-4); nulo se ainda dentro ou sem saída registrada.', NULL),
('bi_fato_permanencia', 'situacao', 13, 'texto', 'concluida (tem saída), em_andamento (ainda dentro) ou sem_saida_registrada (houve nova entrada sem saída — falha de registro).', NULL),
('bi_fato_permanencia', 'permanencia_min', 14, 'inteiro', 'Minutos entre entrada e saída; para em_andamento, até agora; nulo para sem_saida_registrada.', NULL),
('bi_presenca_atual', '*', 0, 'view', 'Quem está dentro agora (último movimento da pessoa no local é uma entrada).', 'Uma linha por pessoa/local dentro'),
('bi_presenca_atual', 'registro_id', 1, 'uuid', 'Movimento de entrada.', NULL),
('bi_presenca_atual', 'pessoa_id', 2, 'uuid', 'Pessoa.', NULL),
('bi_presenca_atual', 'pessoa_nome', 3, 'texto', 'Nome.', NULL),
('bi_presenca_atual', 'pessoa_categoria', 4, 'texto', 'Categoria.', NULL),
('bi_presenca_atual', 'pessoa_empresa', 5, 'texto', 'Empresa.', NULL),
('bi_presenca_atual', 'veiculo_id', 6, 'uuid', 'Veículo da entrada.', NULL),
('bi_presenca_atual', 'veiculo_placa', 7, 'texto', 'Placa.', NULL),
('bi_presenca_atual', 'local_id', 8, 'uuid', 'Local.', NULL),
('bi_presenca_atual', 'local_nome', 9, 'texto', 'Nome do local.', NULL),
('bi_presenca_atual', 'local_tipo', 10, 'texto', 'mina ou escritorio.', NULL),
('bi_presenca_atual', 'destino', 11, 'texto', 'Destino informado.', NULL),
('bi_presenca_atual', 'entrada_em', 12, 'data/hora', 'Data/hora da entrada (UTC-4).', NULL),
('bi_presenca_atual', 'minutos_dentro', 13, 'inteiro', 'Minutos desde a entrada até agora.', NULL),
('bi_presenca_atual', 'acima_alerta_permanencia', 14, 'inteiro', '1 = acima do limite configurado em alerta_permanencia_horas (padrão 14 h).', NULL),
('bi_fato_solicitacao', '*', 0, 'view', 'Solicitações de acesso de visitantes e terceiros, sem documento, contato ou token.', 'Uma linha por solicitação'),
('bi_fato_solicitacao', 'solicitacao_id', 1, 'uuid', 'Chave da solicitação.', NULL),
('bi_fato_solicitacao', 'criada_em', 2, 'data/hora', 'Data/hora de criação (UTC-4).', NULL),
('bi_fato_solicitacao', 'data_criacao', 3, 'data', 'Data de criação.', NULL),
('bi_fato_solicitacao', 'data_prevista', 4, 'data', 'Data prevista da visita.', NULL),
('bi_fato_solicitacao', 'tipo', 5, 'texto', 'visitante ou terceiro.', NULL),
('bi_fato_solicitacao', 'status', 6, 'texto', 'aguardando_ssma, pendente, aprovada, recusada, realizada ou expirada.', NULL),
('bi_fato_solicitacao', 'origem', 7, 'texto', 'interna, link ou publica.', NULL),
('bi_fato_solicitacao', 'atividade_risco', 8, 'inteiro', '1 = exige SSMA.', NULL),
('bi_fato_solicitacao', 'area_id', 9, 'uuid', 'Área (bi_dim_area).', NULL),
('bi_fato_solicitacao', 'area_nome', 10, 'texto', 'Nome da área.', NULL),
('bi_fato_solicitacao', 'local_id', 11, 'uuid', 'Local previsto.', NULL),
('bi_fato_solicitacao', 'local_nome', 12, 'texto', 'Nome do local.', NULL),
('bi_fato_solicitacao', 'empresa', 13, 'texto', 'Empresa do visitante/terceiro.', NULL),
('bi_fato_solicitacao', 'visitante_nome', 14, 'texto', 'Nome informado na solicitação.', NULL),
('bi_fato_solicitacao', 'pessoa_id', 15, 'uuid', 'Pessoa cadastrada vinculada.', NULL),
('bi_fato_solicitacao', 'responsavel_interno_id', 16, 'uuid', 'Anfitrião (bi_dim_usuario).', NULL),
('bi_fato_solicitacao', 'responsavel_interno_nome', 17, 'texto', 'Nome do anfitrião.', NULL),
('bi_fato_solicitacao', 'decidido_por_id', 18, 'uuid', 'Usuário que aprovou/recusou.', NULL),
('bi_fato_solicitacao', 'decidido_por_nome', 19, 'texto', 'Nome de quem decidiu.', NULL),
('bi_fato_solicitacao', 'decidido_em', 20, 'data/hora', 'Data/hora da decisão (UTC-4).', NULL),
('bi_fato_solicitacao', 'minutos_ate_decisao', 21, 'inteiro', 'Tempo de atendimento: criação até decisão, em minutos.', NULL),
('bi_fato_solicitacao', 'consentimento_lgpd', 22, 'inteiro', '1 = aceitou o aviso de privacidade.', NULL),
('bi_fato_solicitacao', 'qtd_entradas', 23, 'inteiro', 'Entradas registradas na portaria para esta solicitação.', NULL),
('bi_fato_verificacao_ssma', '*', 0, 'view', 'Verificações de SSMA (histórico imutável).', 'Uma linha por verificação'),
('bi_fato_verificacao_ssma', 'verificacao_id', 1, 'uuid', 'Chave da verificação.', NULL),
('bi_fato_verificacao_ssma', 'solicitacao_id', 2, 'uuid', 'Solicitação verificada.', NULL),
('bi_fato_verificacao_ssma', 'pessoa_id', 3, 'uuid', 'Pessoa verificada.', NULL),
('bi_fato_verificacao_ssma', 'pessoa_nome', 4, 'texto', 'Nome.', NULL),
('bi_fato_verificacao_ssma', 'status', 5, 'texto', 'apto ou nao_apto.', NULL),
('bi_fato_verificacao_ssma', 'validade_ate', 6, 'data', 'Validade da liberação.', NULL),
('bi_fato_verificacao_ssma', 'verificador_id', 7, 'uuid', 'Usuário de SSMA.', NULL),
('bi_fato_verificacao_ssma', 'verificador_nome', 8, 'texto', 'Nome do verificador.', NULL),
('bi_fato_verificacao_ssma', 'verificado_em', 9, 'data/hora', 'Data/hora (UTC-4).', NULL),
('bi_fato_verificacao_ssma', 'data_verificacao', 10, 'data', 'Data.', NULL),
('bi_fato_verificacao_ssma', 'itens_checklist', 11, 'inteiro', 'Itens no checklist.', NULL),
('bi_fato_verificacao_ssma', 'itens_conformes', 12, 'inteiro', 'Itens marcados como conformes.', NULL),
('bi_fato_documento', '*', 0, 'view', 'Documentos de SSMA (ASO, NRs, integração, CNH) com situação calculada para hoje.', 'Uma linha por documento'),
('bi_fato_documento', 'documento_id', 1, 'uuid', 'Chave do documento.', NULL),
('bi_fato_documento', 'pessoa_id', 2, 'uuid', 'Pessoa.', NULL),
('bi_fato_documento', 'pessoa_nome', 3, 'texto', 'Nome.', NULL),
('bi_fato_documento', 'pessoa_categoria', 4, 'texto', 'Categoria.', NULL),
('bi_fato_documento', 'pessoa_empresa', 5, 'texto', 'Empresa.', NULL),
('bi_fato_documento', 'pessoa_ativa', 6, 'inteiro', '1 = pessoa ativa.', NULL),
('bi_fato_documento', 'tipo', 7, 'texto', 'aso, nr22, nr35, nr33, integracao, cnh ou outro.', NULL),
('bi_fato_documento', 'emissao', 8, 'data', 'Data de emissão.', NULL),
('bi_fato_documento', 'validade', 9, 'data', 'Data de validade.', NULL),
('bi_fato_documento', 'dias_para_vencer', 10, 'inteiro', 'Dias até o vencimento (negativo = vencido).', NULL),
('bi_fato_documento', 'situacao', 11, 'texto', 'vencido, vence_em_30_dias ou valido.', NULL),
('bi_fato_documento', 'mais_recente_do_tipo', 12, 'inteiro', '1 = documento mais recente da pessoa para o tipo. Filtre por 1 para contar situação atual (igual ao painel).', NULL),
('bi_agg_diario_local', '*', 0, 'view', 'Resumo diário por local, pronto para gráficos.', 'Uma linha por dia e local com movimento'),
('bi_agg_diario_local', 'data', 1, 'data', 'Data.', NULL),
('bi_agg_diario_local', 'local_id', 2, 'uuid', 'Local.', NULL),
('bi_agg_diario_local', 'local_nome', 3, 'texto', 'Nome do local.', NULL),
('bi_agg_diario_local', 'entradas', 4, 'decimal', 'Total de entradas.', NULL),
('bi_agg_diario_local', 'saidas', 5, 'decimal', 'Total de saídas.', NULL),
('bi_agg_diario_local', 'entradas_colaboradores', 6, 'decimal', 'Entradas de colaboradores.', NULL),
('bi_agg_diario_local', 'entradas_visitantes', 7, 'decimal', 'Entradas de visitantes.', NULL),
('bi_agg_diario_local', 'entradas_terceiros', 8, 'decimal', 'Entradas de terceiros.', NULL),
('bi_agg_diario_local', 'pessoas_distintas', 9, 'inteiro', 'Pessoas diferentes que entraram.', NULL),
('bi_agg_diario_local', 'veiculos_distintos', 10, 'inteiro', 'Veículos diferentes que entraram.', NULL),
('bi_dicionario', '*', 0, 'tabela', 'Este dicionário de dados da camada de BI.', 'Uma linha por objeto (coluna = "*") e por coluna'),
('bi_dicionario', 'objeto', 1, 'texto', 'Nome da view/tabela.', NULL),
('bi_dicionario', 'coluna', 2, 'texto', 'Nome da coluna ("*" = descrição do objeto).', NULL),
('bi_dicionario', 'ordem', 3, 'inteiro', 'Posição da coluna.', NULL),
('bi_dicionario', 'tipo', 4, 'texto', 'Tipo de dado.', NULL),
('bi_dicionario', 'descricao', 5, 'texto', 'Descrição em linguagem de negócio.', NULL),
('bi_dicionario', 'granularidade', 6, 'texto', 'O que representa cada linha (só na linha "*").', NULL);
