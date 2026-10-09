-- =====================================================================
-- Salinas Access Gate — Schema MariaDB 10.11 / MySQL 8
-- Migração 001: estrutura principal
-- Horário de Mato Grosso – UTC-4 (a aplicação define time_zone = '-04:00')
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Pessoas (colaboradores, visitantes, terceiros)
-- CPF: cifrado com libsodium (cpf_enc) + índice cego HMAC-SHA256 (cpf_hash)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pessoas (
  id                          CHAR(36)     NOT NULL,
  nome                        VARCHAR(200) NOT NULL,
  cpf_enc                     VARBINARY(255) NULL,
  cpf_hash                    CHAR(64)     NULL,
  rg                          VARCHAR(30)  NULL,
  matricula                   VARCHAR(50)  NULL,
  categoria                   ENUM('colaborador','visitante','terceiro') NOT NULL,
  empresa                     VARCHAR(150) NULL,
  funcao                      VARCHAR(120) NULL,
  telefone                    VARCHAR(30)  NULL,
  telefone_principal          VARCHAR(30)  NULL,
  email_principal             VARCHAR(190) NULL,
  whatsapp                    VARCHAR(30)  NULL,
  observacoes_contato         TEXT         NULL,
  destino_padrao              VARCHAR(150) NULL,
  responsavel_interno_padrao  VARCHAR(150) NULL,
  observacoes_portaria_padrao TEXT         NULL,
  observacoes                 TEXT         NULL,
  ativo                       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pessoas_cpf_hash (cpf_hash),
  KEY idx_pessoas_nome (nome),
  KEY idx_pessoas_matricula (matricula),
  KEY idx_pessoas_empresa (empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Usuários do sistema (substitui auth.users + profiles + user_roles)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id                    CHAR(36)     NOT NULL,
  nome                  VARCHAR(150) NOT NULL,
  email                 VARCHAR(190) NOT NULL,
  senha_hash            VARCHAR(255) NOT NULL,
  papel                 ENUM('admin','supervisor','ssma','porteiro') NOT NULL,
  matricula             VARCHAR(50)  NULL,
  ativo                 TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 1,
  seguranca_patrimonial TINYINT(1)   NOT NULL DEFAULT 0,
  pessoa_id             CHAR(36)     NULL,
  ultimo_login_em       DATETIME     NULL,
  senha_alterada_em     DATETIME     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  UNIQUE KEY uq_usuarios_pessoa (pessoa_id),
  CONSTRAINT fk_usuarios_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Áreas (responsáveis por autorizar solicitações)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS areas (
  id                  CHAR(36)     NOT NULL,
  nome                VARCHAR(120) NOT NULL,
  responsavel_user_id CHAR(36)     NULL,
  ativo               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_nome (nome),
  CONSTRAINT fk_areas_resp FOREIGN KEY (responsavel_user_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Locais / portarias
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locais (
  id         CHAR(36)     NOT NULL,
  nome       VARCHAR(150) NOT NULL,
  tipo       ENUM('mina','escritorio') NOT NULL,
  endereco   VARCHAR(255) NULL,
  ativo      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Veículos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS veiculos (
  id                       CHAR(36)     NOT NULL,
  placa                    VARCHAR(10)  NOT NULL,
  tipo                     VARCHAR(30)  NOT NULL DEFAULT 'carro',
  marca_modelo             VARCHAR(120) NULL,
  cor                      VARCHAR(40)  NULL,
  empresa                  VARCHAR(150) NULL,
  tag                      VARCHAR(60)  NULL,
  comissionado_mina        TINYINT(1)   NOT NULL DEFAULT 0,
  comissionamento_validade DATE         NULL,
  observacoes              TEXT         NULL,
  ativo                    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_veiculos_placa (placa),
  UNIQUE KEY uq_veiculos_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Documentos da pessoa (ASO, NRs, integração, CNH)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
  id          CHAR(36)    NOT NULL,
  pessoa_id   CHAR(36)    NOT NULL,
  tipo        ENUM('aso','nr22','nr35','nr33','integracao','cnh','outro') NOT NULL,
  numero      VARCHAR(60) NULL,
  emissao     DATE        NULL,
  validade    DATE        NOT NULL,
  observacoes TEXT        NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_documentos_pessoa (pessoa_id),
  KEY idx_documentos_validade (validade),
  CONSTRAINT fk_documentos_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Solicitações de acesso (visitantes / terceiros)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitacoes_acesso (
  id                     CHAR(36)     NOT NULL,
  visitante_nome         VARCHAR(200) NOT NULL,
  visitante_doc          VARCHAR(30)  NULL,
  empresa                VARCHAR(150) NULL,
  contato_telefone       VARCHAR(30)  NULL,
  contato_email          VARCHAR(190) NULL,
  tipo                   ENUM('visitante','terceiro') NOT NULL DEFAULT 'visitante',
  area_id                CHAR(36)     NULL,
  responsavel_interno_id CHAR(36)     NULL,
  pessoa_id              CHAR(36)     NULL,
  local_id               CHAR(36)     NULL,
  data_prevista          DATE         NOT NULL,
  motivo                 TEXT         NULL,
  atividade_risco        TINYINT(1)   NOT NULL DEFAULT 0,
  status                 ENUM('aguardando_ssma','pendente','aprovada','recusada','realizada','expirada') NOT NULL DEFAULT 'pendente',
  status_observacao      TEXT         NULL,
  origem                 ENUM('interna','link','publica') NOT NULL DEFAULT 'interna',
  token_externo          VARCHAR(64)  NULL,
  link_expira_em         DATETIME     NULL,
  aguardando_preenchimento TINYINT(1) NOT NULL DEFAULT 0,
  autorizado_por         CHAR(36)     NULL,
  autorizado_em          DATETIME     NULL,
  motivo_recusa          TEXT         NULL,
  consentimento_lgpd     TINYINT(1)   NOT NULL DEFAULT 0,
  criado_por             CHAR(36)     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_solicitacoes_token (token_externo),
  KEY idx_solicitacoes_status (status),
  KEY idx_solicitacoes_area (area_id),
  KEY idx_solicitacoes_pessoa (pessoa_id),
  KEY idx_solicitacoes_data (data_prevista),
  CONSTRAINT fk_sol_area   FOREIGN KEY (area_id)                REFERENCES areas (id)    ON DELETE SET NULL,
  CONSTRAINT fk_sol_resp   FOREIGN KEY (responsavel_interno_id) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_sol_pessoa FOREIGN KEY (pessoa_id)              REFERENCES pessoas (id)  ON DELETE SET NULL,
  CONSTRAINT fk_sol_local  FOREIGN KEY (local_id)               REFERENCES locais (id)   ON DELETE SET NULL,
  CONSTRAINT fk_sol_autor  FOREIGN KEY (autorizado_por)         REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_sol_criad  FOREIGN KEY (criado_por)             REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Verificações SSMA (histórico imutável)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verificacoes_ssma (
  id                  CHAR(36)     NOT NULL,
  solicitacao_id      CHAR(36)     NOT NULL,
  pessoa_id           CHAR(36)     NULL,
  status              ENUM('apto','nao_apto') NOT NULL,
  observacao          TEXT         NULL,
  checklist           LONGTEXT     NOT NULL CHECK (JSON_VALID(checklist)),
  validade_ate        DATE         NULL,
  verificado_por      CHAR(36)     NULL,
  verificado_por_nome VARCHAR(150) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verif_solicitacao (solicitacao_id, created_at),
  CONSTRAINT fk_verif_sol    FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_acesso (id) ON DELETE RESTRICT,
  CONSTRAINT fk_verif_pessoa FOREIGN KEY (pessoa_id)      REFERENCES pessoas (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_verif_user   FOREIGN KEY (verificado_por) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Registros de acesso (APPEND-ONLY)
-- Obs.: no MariaDB, ações de FK (SET NULL/CASCADE) NÃO disparam triggers;
-- por isso as tabelas imutáveis usam apenas ON DELETE RESTRICT.
-- seq: ordem física de inserção (desempate de data_hora)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registros_acesso (
  id                           CHAR(36)     NOT NULL,
  seq                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pessoa_id                    CHAR(36)     NULL,
  veiculo_id                   CHAR(36)     NULL,
  local_id                     CHAR(36)     NOT NULL,
  solicitacao_id               CHAR(36)     NULL,
  sentido                      ENUM('entrada','saida') NOT NULL,
  data_hora                    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  destino                      VARCHAR(150) NULL,
  responsavel_interno          VARCHAR(150) NULL,
  responsavel_interno_id       CHAR(36)     NULL,
  responsavel_interno_nome     VARCHAR(150) NULL,
  responsavel_interno_telefone VARCHAR(30)  NULL,
  responsavel_interno_email    VARCHAR(190) NULL,
  porteiro_id                  CHAR(36)     NOT NULL,
  observacoes                  TEXT         NULL,
  created_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registros_seq (seq),
  KEY idx_registros_local_data   (local_id, data_hora),
  KEY idx_registros_pessoa_data  (pessoa_id, data_hora),
  KEY idx_registros_veiculo_data (veiculo_id, data_hora),
  KEY idx_registros_data         (data_hora),
  CONSTRAINT fk_reg_pessoa   FOREIGN KEY (pessoa_id)   REFERENCES pessoas (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_reg_veiculo  FOREIGN KEY (veiculo_id)  REFERENCES veiculos (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_local    FOREIGN KEY (local_id)    REFERENCES locais (id)   ON DELETE RESTRICT,
  CONSTRAINT fk_reg_sol      FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_acesso (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_resp     FOREIGN KEY (responsavel_interno_id) REFERENCES pessoas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_reg_porteiro FOREIGN KEY (porteiro_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fotos/anexos dos registros (APPEND-ONLY). Arquivos em storage/uploads
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registro_anexos (
  id                 CHAR(36)     NOT NULL,
  registro_acesso_id CHAR(36)     NOT NULL,
  storage_path       VARCHAR(255) NOT NULL,
  tipo_foto          ENUM('pessoa','veiculo','placa','documento','outra') NOT NULL DEFAULT 'outra',
  nome_arquivo       VARCHAR(255) NULL,
  mime_type          VARCHAR(60)  NULL,
  tamanho_bytes      BIGINT       NULL,
  sha256             CHAR(64)     NULL,
  criado_por         CHAR(36)     NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_anexos_registro (registro_acesso_id),
  CONSTRAINT fk_anexo_registro FOREIGN KEY (registro_acesso_id) REFERENCES registros_acesso (id) ON DELETE RESTRICT,
  CONSTRAINT fk_anexo_user     FOREIGN KEY (criado_por) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Trilha de auditoria (APPEND-ONLY)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id          CHAR(36)     NOT NULL,
  seq         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entidade    VARCHAR(60)  NOT NULL,
  registro_id VARCHAR(64)  NULL,
  acao        VARCHAR(60)  NOT NULL,
  usuario_id  CHAR(36)     NULL,
  detalhes    LONGTEXT     NULL CHECK (detalhes IS NULL OR JSON_VALID(detalhes)),
  ip          VARCHAR(45)  NULL,
  data_hora   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_seq (seq),
  KEY idx_audit_data (data_hora),
  KEY idx_audit_entidade (entidade, registro_id),
  KEY idx_audit_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Configurações gerais (chave/valor)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracoes (
  chave      VARCHAR(80) NOT NULL,
  valor      TEXT        NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by CHAR(36)    NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Controle de tentativas (login e formulário público)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chave     VARCHAR(190) NOT NULL,
  criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate_chave (chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- TRIGGERS — imutabilidade (append-only) e auditoria automática
-- Para importar dados históricos sem duplicar auditoria:
--   SET @sag_import = 1;
-- =====================================================================
DROP TRIGGER IF EXISTS trg_registros_no_update;
DROP TRIGGER IF EXISTS trg_registros_no_delete;
DROP TRIGGER IF EXISTS trg_registros_audit;
DROP TRIGGER IF EXISTS trg_audit_no_update;
DROP TRIGGER IF EXISTS trg_audit_no_delete;
DROP TRIGGER IF EXISTS trg_verif_no_update;
DROP TRIGGER IF EXISTS trg_verif_no_delete;
DROP TRIGGER IF EXISTS trg_anexos_no_update;
DROP TRIGGER IF EXISTS trg_anexos_no_delete;
DROP TRIGGER IF EXISTS trg_sol_status_inicial;

DELIMITER $$

CREATE TRIGGER trg_registros_no_update BEFORE UPDATE ON registros_acesso
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registros_acesso é append-only: alteração não permitida';
END$$

CREATE TRIGGER trg_registros_no_delete BEFORE DELETE ON registros_acesso
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registros_acesso é append-only: exclusão não permitida';
END$$

CREATE TRIGGER trg_registros_audit AFTER INSERT ON registros_acesso
FOR EACH ROW BEGIN
  IF @sag_import IS NULL THEN
    INSERT INTO audit_log (id, entidade, registro_id, acao, usuario_id, detalhes, ip)
    VALUES (
      UUID(), 'registros_acesso', NEW.id, 'INSERT', NEW.porteiro_id,
      JSON_OBJECT(
        'sentido', NEW.sentido,
        'local_id', NEW.local_id,
        'pessoa_id', NEW.pessoa_id,
        'veiculo_id', NEW.veiculo_id,
        'solicitacao_id', NEW.solicitacao_id,
        'destino', NEW.destino
      ),
      @sag_ip
    );
  END IF;
END$$

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log é append-only: alteração não permitida';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log é append-only: exclusão não permitida';
END$$

CREATE TRIGGER trg_verif_no_update BEFORE UPDATE ON verificacoes_ssma
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verificacoes_ssma é imutável: alteração não permitida';
END$$

CREATE TRIGGER trg_verif_no_delete BEFORE DELETE ON verificacoes_ssma
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verificacoes_ssma é imutável: exclusão não permitida';
END$$

CREATE TRIGGER trg_anexos_no_update BEFORE UPDATE ON registro_anexos
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registro_anexos é append-only: alteração não permitida';
END$$

CREATE TRIGGER trg_anexos_no_delete BEFORE DELETE ON registro_anexos
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registro_anexos é append-only: exclusão não permitida';
END$$

CREATE TRIGGER trg_sol_status_inicial BEFORE INSERT ON solicitacoes_acesso
FOR EACH ROW BEGIN
  IF NEW.atividade_risco = 1 AND NEW.status = 'pendente' AND NEW.aguardando_preenchimento = 0 THEN
    SET NEW.status = 'aguardando_ssma';
  END IF;
END$$

DELIMITER ;

-- =====================================================================
-- VIEWS — presença (último movimento por pessoa/local e veículo/local)
-- =====================================================================
CREATE OR REPLACE VIEW vw_ultimo_mov_pessoa AS
SELECT t.registro_id, t.pessoa_id, t.veiculo_id, t.local_id, t.destino, t.sentido, t.data_hora
FROM (
  SELECT r.id AS registro_id, r.pessoa_id, r.veiculo_id, r.local_id, r.destino, r.sentido, r.data_hora,
         ROW_NUMBER() OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora DESC, r.seq DESC) AS rn
  FROM registros_acesso r
  WHERE r.pessoa_id IS NOT NULL
) t
WHERE t.rn = 1;

CREATE OR REPLACE VIEW vw_ultimo_mov_veiculo AS
SELECT t.registro_id, t.veiculo_id, t.local_id, t.sentido, t.data_hora
FROM (
  SELECT r.id AS registro_id, r.veiculo_id, r.local_id, r.sentido, r.data_hora,
         ROW_NUMBER() OVER (PARTITION BY r.veiculo_id, r.local_id ORDER BY r.data_hora DESC, r.seq DESC) AS rn
  FROM registros_acesso r
  WHERE r.veiculo_id IS NOT NULL
) t
WHERE t.rn = 1;

CREATE OR REPLACE VIEW vw_presenca_dentro AS
SELECT u.registro_id, u.pessoa_id, p.nome AS pessoa_nome, p.categoria AS pessoa_categoria,
       p.empresa AS pessoa_empresa, u.veiculo_id, v.placa AS veiculo_placa,
       u.local_id, l.nome AS local_nome, l.tipo AS local_tipo, u.destino, u.data_hora AS entrada_em
FROM vw_ultimo_mov_pessoa u
JOIN pessoas p  ON p.id = u.pessoa_id
JOIN locais  l  ON l.id = u.local_id
LEFT JOIN veiculos v ON v.id = u.veiculo_id
WHERE u.sentido = 'entrada';
