-- Calendário copiado na abertura: alterações futuras não reescrevem SLAs em andamento.
-- NULL preserva os chamados anteriores em regime 24x7.
ALTER TABLE chamados
  ADD COLUMN anonimizado_em DATETIME NULL,
  ADD COLUMN fechado_automaticamente TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN calendario_sla JSON NULL;
ALTER TABLE servico_categorias
  ADD COLUMN usar_horario_comercial TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS chamado_satisfacao (
  chamado_id CHAR(36) NOT NULL PRIMARY KEY,
  nota TINYINT NOT NULL,
  comentario TEXT NULL,
  respondido_por CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chamado_id) REFERENCES chamados(id),
  FOREIGN KEY (respondido_por) REFERENCES usuarios(id),
  CHECK (nota BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_fila (
  id CHAR(36) NOT NULL PRIMARY KEY,
  para VARCHAR(190) NOT NULL,
  assunto VARCHAR(200) NOT NULL,
  corpo_texto TEXT NOT NULL,
  corpo_html MEDIUMTEXT NULL,
  referencia VARCHAR(80) NULL,
  tentativas TINYINT NOT NULL DEFAULT 0,
  ultimo_erro VARCHAR(500) NULL,
  enviar_apos DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_email_fila_pendente (enviado_em, enviar_apos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
('horario_comercial_ativo', '1'), ('horario_dias', '1,2,3,4,5'),
('horario_inicio', '07:00'), ('horario_fim', '17:00'), ('feriados', ''),
('retencao_chamados_meses', '24'), ('email_ativo', '0'), ('chamados_fechar_dias', '7');
