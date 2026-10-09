-- =====================================================================
-- Migração 002: dados iniciais (locais, áreas e configurações)
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO locais (id, nome, tipo, endereco) VALUES
  ('5a1d0c3e-0001-4000-8000-000000000001', 'Portaria Mina - Salinas', 'mina', 'Estrada da Salinas, Km 20 - N. Sra. do Livramento/MT'),
  ('5a1d0c3e-0001-4000-8000-000000000002', 'Portaria Escritório Cuiabá', 'escritorio', 'Cuiabá/MT');

INSERT IGNORE INTO areas (id, nome) VALUES
  ('5a1d0c3e-0002-4000-8000-000000000001', 'Tecnologia da Informação'),
  ('5a1d0c3e-0002-4000-8000-000000000002', 'Suprimentos'),
  ('5a1d0c3e-0002-4000-8000-000000000003', 'Almoxarifado'),
  ('5a1d0c3e-0002-4000-8000-000000000004', 'Contabilidade'),
  ('5a1d0c3e-0002-4000-8000-000000000005', 'Manutenção'),
  ('5a1d0c3e-0002-4000-8000-000000000006', 'Beneficiamento'),
  ('5a1d0c3e-0002-4000-8000-000000000007', 'Geologia'),
  ('5a1d0c3e-0002-4000-8000-000000000008', 'Mina');

INSERT IGNORE INTO configuracoes (chave, valor) VALUES
  ('org_nome', 'Salinas Gold Mineração LTDA'),
  ('org_cnpj', '03.212.955/0001-09'),
  ('exigir_destino', '0'),
  ('alerta_permanencia_horas', '8'),
  ('link_externo_validade_horas', '72'),
  ('form_publico_ativo', '1');
