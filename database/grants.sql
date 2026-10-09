-- =====================================================================
-- (OPCIONAL) Endurecimento de privilégios do usuário da aplicação
-- Execute como root DEPOIS de "php bin/console.php migrar".
-- O usuário de aplicação passa a NÃO ter UPDATE/DELETE nas tabelas imutáveis
-- (defesa em profundidade além das triggers append-only).
-- Ajuste o nome do banco/usuário/host conforme o seu ambiente.
-- =====================================================================
-- REVOKE ALL PRIVILEGES ON salinas_access_gate.* FROM 'sag_app'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.pessoas             TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.usuarios            TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.areas               TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.locais              TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.veiculos            TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.documentos          TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON salinas_access_gate.solicitacoes_acesso TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON salinas_access_gate.configuracoes       TO 'sag_app'@'localhost';
GRANT SELECT, INSERT, DELETE         ON salinas_access_gate.rate_limits         TO 'sag_app'@'localhost';
GRANT SELECT, INSERT                 ON salinas_access_gate.registros_acesso    TO 'sag_app'@'localhost';
GRANT SELECT, INSERT                 ON salinas_access_gate.registro_anexos     TO 'sag_app'@'localhost';
GRANT SELECT, INSERT                 ON salinas_access_gate.verificacoes_ssma   TO 'sag_app'@'localhost';
GRANT SELECT, INSERT                 ON salinas_access_gate.audit_log           TO 'sag_app'@'localhost';
GRANT SELECT                         ON salinas_access_gate.vw_ultimo_mov_pessoa  TO 'sag_app'@'localhost';
GRANT SELECT                         ON salinas_access_gate.vw_ultimo_mov_veiculo TO 'sag_app'@'localhost';
GRANT SELECT                         ON salinas_access_gate.vw_presenca_dentro    TO 'sag_app'@'localhost';
GRANT SELECT, INSERT                 ON salinas_access_gate.schema_migrations   TO 'sag_app'@'localhost';
FLUSH PRIVILEGES;
-- Migrações futuras (CREATE/ALTER/TRIGGER) devem ser rodadas com um usuário administrativo.
