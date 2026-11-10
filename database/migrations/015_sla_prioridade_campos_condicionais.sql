-- Regras opcionais: a ausência de linha mantém exatamente o SLA já configurado.
CREATE TABLE IF NOT EXISTS servico_sla_prioridades (
  id CHAR(36) NOT NULL PRIMARY KEY,
  area_id CHAR(36) NOT NULL,
  categoria_id CHAR(36) NULL,
  prioridade TINYINT NOT NULL,
  sla_resposta_min INT NULL,
  sla_solucao_min INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sla_prioridade (area_id, categoria_id, prioridade),
  FOREIGN KEY (area_id) REFERENCES servico_areas(id),
  FOREIGN KEY (categoria_id, area_id) REFERENCES servico_categorias(id, area_id),
  CHECK (prioridade BETWEEN 1 AND 6),
  CHECK (sla_resposta_min IS NULL OR sla_resposta_min BETWEEN 1 AND 525600),
  CHECK (sla_solucao_min IS NULL OR sla_solucao_min BETWEEN 1 AND 525600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE servico_campos
  ADD COLUMN depende_campo_id CHAR(36) NULL,
  ADD COLUMN depende_valor VARCHAR(200) NULL,
  ADD COLUMN depende_obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN depende_urgencia_max TINYINT NULL,
  ADD CONSTRAINT fk_servico_campo_dependencia FOREIGN KEY (depende_campo_id) REFERENCES servico_campos(id),
  ADD CHECK (depende_urgencia_max IS NULL OR depende_urgencia_max BETWEEN 1 AND 5);
