-- =====================================================================
-- Migração 003: vínculo de usuários com Active Directory / LDAP
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE usuarios
  ADD COLUMN auth_origem ENUM('local','ldap') NOT NULL DEFAULT 'local' AFTER senha_hash,
  ADD COLUMN ldap_guid VARCHAR(64) NULL AFTER auth_origem,
  ADD COLUMN ldap_usuario VARCHAR(190) NULL AFTER ldap_guid,
  ADD COLUMN ldap_dn VARCHAR(500) NULL AFTER ldap_usuario,
  ADD COLUMN ldap_sincronizado_em DATETIME NULL AFTER ldap_dn,
  ADD UNIQUE KEY uq_usuarios_ldap_guid (ldap_guid),
  ADD KEY idx_usuarios_ldap_usuario (ldap_usuario);
