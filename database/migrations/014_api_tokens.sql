-- Credenciais de consulta para BI. Apenas o hash do segredo é persistido.
CREATE TABLE IF NOT EXISTS api_tokens (
  id CHAR(36) NOT NULL PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  prefixo CHAR(8) NOT NULL,
  hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  escopos JSON NOT NULL,
  criado_por CHAR(36) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em DATETIME NULL,
  ultimo_uso_em DATETIME NULL,
  ultimo_ip VARCHAR(45) NULL,
  revogado_em DATETIME NULL,
  revogado_por CHAR(36) NULL,
  FOREIGN KEY (criado_por) REFERENCES usuarios(id),
  FOREIGN KEY (revogado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
