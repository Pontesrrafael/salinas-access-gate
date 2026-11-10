-- Cores distintas para as 4 áreas da semente; preserva cores já personalizadas pelo Admin.
UPDATE servico_areas SET cor = '#B54708' WHERE slug = 'ssma' AND cor = '#024B37';
UPDATE servico_areas SET cor = '#024B37' WHERE slug = 'facilities' AND cor = '#024B37';
UPDATE servico_areas SET cor = '#3E7B27' WHERE slug = 'meio-ambiente' AND cor = '#024B37';
UPDATE servico_areas SET cor = '#1D4ED8' WHERE slug = 'suprimentos' AND cor = '#024B37';
