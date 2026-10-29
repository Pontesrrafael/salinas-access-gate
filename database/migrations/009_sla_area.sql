-- Padrões por área; NULL na categoria herda da área, sem alterar valores existentes.
ALTER TABLE servico_areas
  ADD COLUMN sla_resposta_min INT NULL,
  ADD COLUMN sla_solucao_min INT NULL,
  ADD COLUMN usar_horario_comercial TINYINT(1) NULL;

ALTER TABLE servico_categorias
  MODIFY COLUMN usar_horario_comercial TINYINT(1) NULL DEFAULT NULL;
