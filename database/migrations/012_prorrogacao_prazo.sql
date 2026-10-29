-- Acúmulo de minutos prorrogados por chamado para relatórios e evento específico na linha do tempo.
ALTER TABLE chamados
  ADD COLUMN prazo_prorrogado_min INT NOT NULL DEFAULT 0;

ALTER TABLE chamado_acompanhamentos
  MODIFY COLUMN tipo ENUM('comentario','interno','solucao','status','atribuicao','prorrogacao') NOT NULL;
