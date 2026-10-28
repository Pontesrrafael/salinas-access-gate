-- Serviços por área: separado das solicitações de acesso e das áreas da portaria.
CREATE TABLE IF NOT EXISTS servico_areas (
  id CHAR(36) NOT NULL PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE,
  descricao TEXT NULL,
  cor CHAR(7) NOT NULL DEFAULT '#024B37',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servico_area_membros (
  area_id CHAR(36) NOT NULL,
  usuario_id CHAR(36) NOT NULL,
  papel ENUM('atendente','gestor') NOT NULL DEFAULT 'atendente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (area_id, usuario_id),
  FOREIGN KEY (area_id) REFERENCES servico_areas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servico_categorias (
  id CHAR(36) NOT NULL PRIMARY KEY,
  area_id CHAR(36) NOT NULL,
  parent_id CHAR(36) NULL,
  nome VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  sla_resposta_min INT NULL,
  sla_solucao_min INT NULL,
  urgencia_padrao TINYINT NOT NULL DEFAULT 3,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ordem INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categoria_area (id, area_id),
  FOREIGN KEY (area_id) REFERENCES servico_areas(id),
  FOREIGN KEY (parent_id, area_id) REFERENCES servico_categorias(id, area_id),
  CHECK (urgencia_padrao BETWEEN 1 AND 5),
  CHECK (sla_resposta_min IS NULL OR sla_resposta_min > 0),
  CHECK (sla_solucao_min IS NULL OR sla_solucao_min > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servico_campos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  area_id CHAR(36) NOT NULL,
  categoria_id CHAR(36) NULL,
  rotulo VARCHAR(200) NOT NULL,
  ajuda TEXT NULL,
  tipo ENUM('texto','texto_longo','numero','data','lista','multipla','sim_nao','anexo') NOT NULL,
  opcoes JSON NULL,
  obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
  ordem INT NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (area_id) REFERENCES servico_areas(id),
  FOREIGN KEY (categoria_id, area_id) REFERENCES servico_categorias(id, area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamados (
  id CHAR(36) NOT NULL PRIMARY KEY,
  numero BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
  area_id CHAR(36) NOT NULL,
  categoria_id CHAR(36) NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  descricao TEXT NULL,
  solicitante_id CHAR(36) NOT NULL,
  beneficiario_nome VARCHAR(150) NULL,
  recorrente TINYINT(1) NOT NULL DEFAULT 0,
  urgencia TINYINT NOT NULL DEFAULT 3,
  impacto TINYINT NOT NULL DEFAULT 1,
  prioridade TINYINT NOT NULL DEFAULT 4,
  status ENUM('novo','atribuido','planejado','pendente','solucionado','fechado') NOT NULL DEFAULT 'novo',
  atendente_id CHAR(36) NULL,
  prazo_resposta DATETIME NULL,
  prazo_solucao DATETIME NULL,
  respondido_em DATETIME NULL,
  solucionado_em DATETIME NULL,
  fechado_em DATETIME NULL,
  pendente_desde DATETIME NULL,
  segundos_pendente INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_chamados_fila (area_id, status, prioridade, created_at),
  KEY ix_chamados_solicitante (solicitante_id, created_at),
  KEY ix_chamados_fechamento (status, solucionado_em),
  FOREIGN KEY (area_id) REFERENCES servico_areas(id),
  FOREIGN KEY (categoria_id, area_id) REFERENCES servico_categorias(id, area_id),
  FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  FOREIGN KEY (atendente_id) REFERENCES usuarios(id),
  CHECK (urgencia BETWEEN 1 AND 5),
  CHECK (impacto BETWEEN 1 AND 4),
  CHECK (prioridade BETWEEN 1 AND 6),
  CHECK (segundos_pendente >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_respostas (
  chamado_id CHAR(36) NOT NULL,
  campo_id CHAR(36) NOT NULL,
  rotulo VARCHAR(200) NOT NULL,
  valor TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chamado_id, campo_id),
  FOREIGN KEY (chamado_id) REFERENCES chamados(id),
  FOREIGN KEY (campo_id) REFERENCES servico_campos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_acompanhamentos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  chamado_id CHAR(36) NOT NULL,
  usuario_id CHAR(36) NULL,
  tipo ENUM('comentario','interno','solucao','status','atribuicao') NOT NULL,
  texto TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_acompanhamentos_chamado (chamado_id, created_at),
  FOREIGN KEY (chamado_id) REFERENCES chamados(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chamado_anexos (
  id CHAR(36) NOT NULL PRIMARY KEY,
  chamado_id CHAR(36) NOT NULL,
  acompanhamento_id CHAR(36) NULL,
  storage_path VARCHAR(255) NOT NULL,
  nome_arquivo VARCHAR(200) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  tamanho_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  criado_por CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chamado_id) REFERENCES chamados(id),
  FOREIGN KEY (acompanhamento_id) REFERENCES chamado_acompanhamentos(id),
  FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO servico_areas (id, nome, slug, ordem) VALUES
('e4ef6cc8-aafe-4ad4-80be-a2420a856801', 'SSMA', 'ssma', 10),
('e4ef6cc8-aafe-4ad4-80be-a2420a856802', 'Facilities', 'facilities', 20),
('e4ef6cc8-aafe-4ad4-80be-a2420a856803', 'Meio Ambiente', 'meio-ambiente', 30),
('e4ef6cc8-aafe-4ad4-80be-a2420a856804', 'Suprimentos', 'suprimentos', 40);
INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('chamados_ativo', '1'), ('chamados_fechar_dias', '7');
