-- =====================================================================
-- Migração 005: LGPD (aviso de privacidade, retenção e anonimização)
-- e autenticação em dois fatores (TOTP) para contas locais.
-- =====================================================================
SET NAMES utf8mb4;

-- ---- MFA (TOTP, RFC 6238) — segredo cifrado com chave derivada da chave do CPF
ALTER TABLE usuarios
  ADD COLUMN mfa_segredo_enc VARBINARY(255) NULL AFTER senha_alterada_em,
  ADD COLUMN mfa_ativo       TINYINT(1)     NOT NULL DEFAULT 0 AFTER mfa_segredo_enc,
  ADD COLUMN mfa_ultimo_passo BIGINT        NULL AFTER mfa_ativo,
  ADD COLUMN mfa_ativado_em  DATETIME       NULL AFTER mfa_ultimo_passo;

-- ---- Marca de anonimização (LGPD art. 16 / ISO 27001 A.8.10)
ALTER TABLE pessoas
  ADD COLUMN anonimizado_em DATETIME NULL AFTER ativo;
ALTER TABLE solicitacoes_acesso
  ADD COLUMN anonimizado_em DATETIME NULL AFTER consentimento_lgpd;

-- ---- Configurações padrão (editáveis em Configurações → Privacidade e retenção)
INSERT IGNORE INTO configuracoes (chave, valor) VALUES
  ('encarregado_nome', NULL),
  ('encarregado_email', NULL),
  ('retencao_registros_meses', '24'),
  ('retencao_fotos_dias', '180'),
  ('retencao_cadastros_meses', '24'),
  ('retencao_solicitacoes_meses', '12'),
  ('retencao_auditoria_meses', '60'),
  ('mfa_obrigatorio_admin', '1');

-- =====================================================================
-- Triggers: a imutabilidade continua, com UMA exceção controlada —
-- o comando `aplicar-retencao` define @sag_retencao = 1 e só pode:
--   * limpar observações livres de registros antigos (identidade, local,
--     sentido, data e porteiro continuam intactos);
--   * apagar anexos (fotos) vencidos;
--   * apagar auditoria com mais de 6 meses (Marco Civil, art. 15).
-- =====================================================================
DROP TRIGGER IF EXISTS trg_registros_no_update;
DROP TRIGGER IF EXISTS trg_anexos_no_delete;
DROP TRIGGER IF EXISTS trg_audit_no_delete;

DELIMITER $$

CREATE TRIGGER trg_registros_no_update BEFORE UPDATE ON registros_acesso
FOR EACH ROW BEGIN
  IF NOT (COALESCE(@sag_retencao, 0) = 1
          AND NEW.id <=> OLD.id AND NEW.seq <=> OLD.seq
          AND NEW.pessoa_id <=> OLD.pessoa_id AND NEW.veiculo_id <=> OLD.veiculo_id
          AND NEW.local_id <=> OLD.local_id AND NEW.solicitacao_id <=> OLD.solicitacao_id
          AND NEW.sentido <=> OLD.sentido AND NEW.data_hora <=> OLD.data_hora
          AND NEW.porteiro_id <=> OLD.porteiro_id AND NEW.created_at <=> OLD.created_at
          AND NEW.observacoes IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registros_acesso é append-only: alteração não permitida';
  END IF;
END$$

CREATE TRIGGER trg_anexos_no_delete BEFORE DELETE ON registro_anexos
FOR EACH ROW BEGIN
  IF NOT (COALESCE(@sag_retencao, 0) = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registro_anexos é append-only: exclusão não permitida';
  END IF;
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
  IF NOT (COALESCE(@sag_retencao, 0) = 1 AND OLD.data_hora < (NOW() - INTERVAL 6 MONTH)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log é append-only: exclusão não permitida';
  END IF;
END$$

DELIMITER ;
