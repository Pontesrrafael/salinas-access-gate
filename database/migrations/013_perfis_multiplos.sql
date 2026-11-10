-- Perfis concedidos separados do perfil padrão de login (usuarios.papel).
ALTER TABLE usuarios
  MODIFY COLUMN papel ENUM('admin','supervisor','ssma','porteiro','colaborador','gestor_area') NOT NULL;

CREATE TABLE IF NOT EXISTS usuario_perfis (
  usuario_id CHAR(36) NOT NULL,
  perfil ENUM('admin','supervisor','ssma','porteiro','colaborador','gestor_area') NOT NULL,
  PRIMARY KEY (usuario_id, perfil),
  CONSTRAINT fk_usuario_perfis_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO usuario_perfis (usuario_id, perfil)
  SELECT id, papel FROM usuarios;

INSERT IGNORE INTO usuario_perfis (usuario_id, perfil)
  SELECT usuario_id, 'gestor_area' FROM servico_area_membros WHERE papel = 'gestor';
