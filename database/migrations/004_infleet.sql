-- Integração opcional Infleet: vínculos e metadados; autorização de portaria continua local.
CREATE TABLE IF NOT EXISTS infleet_veiculos (
  id VARCHAR(100) NOT NULL,
  veiculo_id CHAR(36) NULL,
  placa VARCHAR(100) NOT NULL,
  marca_modelo VARCHAR(120) NULL,
  tipo VARCHAR(40) NULL,
  odometro DECIMAL(15,3) NULL,
  ativo TINYINT(1) NOT NULL,
  motorista_id VARCHAR(100) NULL,
  disponivel TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_infleet_veiculo (veiculo_id),
  CONSTRAINT fk_infleet_veiculo FOREIGN KEY (veiculo_id) REFERENCES veiculos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS infleet_motoristas (
  id VARCHAR(100) NOT NULL,
  pessoa_id CHAR(36) NULL,
  nome VARCHAR(200) NOT NULL,
  cpf_enc VARBINARY(255) NULL,
  cpf_hash CHAR(64) NULL,
  ativo TINYINT(1) NOT NULL,
  veiculo_infleet_id VARCHAR(100) NULL,
  disponivel TINYINT(1) NOT NULL DEFAULT 1,
  sincronizado_em DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_infleet_pessoa (pessoa_id),
  KEY idx_infleet_cpf (cpf_hash),
  CONSTRAINT fk_infleet_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS infleet_sincronizacoes (
  id CHAR(36) NOT NULL,
  concluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  veiculos INT NOT NULL,
  motoristas INT NOT NULL,
  pendencias INT NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
