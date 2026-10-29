-- Escala única: 1 = Muito alta ... 5 = Muito baixa. Não inverter dados existentes.
-- O administrador deve conferir a intenção das urgências já cadastradas.
ALTER TABLE usuarios MODIFY papel ENUM('admin','supervisor','ssma','porteiro','colaborador') NOT NULL DEFAULT 'porteiro';
ALTER TABLE chamados
  MODIFY solicitante_id CHAR(36) NULL,
  ADD COLUMN retencao_versao TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN sla_inicio DATETIME NULL,
  ADD COLUMN sla_resposta_snapshot JSON NULL;
ALTER TABLE chamado_satisfacao MODIFY respondido_por CHAR(36) NULL;
ALTER TABLE chamado_anexos
  ADD COLUMN campo_id CHAR(36) NULL,
  ADD CONSTRAINT fk_anexo_campo FOREIGN KEY (campo_id) REFERENCES servico_campos(id);
ALTER TABLE email_fila
  ADD COLUMN reserva_token CHAR(36) NULL,
  ADD COLUMN reservado_em DATETIME NULL,
  ADD KEY ix_email_reserva (reserva_token, reservado_em);
