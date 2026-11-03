-- Preferências individuais de notificação por e-mail nos chamados (ausência de linha = ligado).
CREATE TABLE IF NOT EXISTS usuario_notificacoes (
  usuario_id CHAR(36) NOT NULL,
  evento VARCHAR(40) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, evento),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
