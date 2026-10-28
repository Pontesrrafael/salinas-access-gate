# Idiomas da interface do Access Gate

## Uso

Português brasileiro é o padrão. O seletor **Idioma** oferece Português, English e Español no login, formulários públicos e menu das telas autenticadas. Selecione a opção e use **Alterar idioma**. A preferência permanece neste navegador em cookie `sag_idioma`, inclusive após navegação e login. Ela não é sincronizada entre equipamentos nem vinculada à conta.

A troca usa GET e mantém filtros de consulta, sem reenviar formulários POST. Em uma tela de edição, salve ou conclua seu trabalho antes de trocar o idioma, pois a página recarrega. Datas operacionais continuam no fuso America/Cuiaba; documentos brasileiros, máscaras de CPF e telefone e valores internos mantêm suas regras. O idioma não habilita recursos nem modifica permissões.

## Implementação

- `app/Core/I18n.php`: allowlist pt-BR/en/es, leitura segura da preferência, catálogos e substituição explícita de parâmetros.
- `app/Lang/en.json` e `es.json`: textos originais em português como chaves, com traduções locais. Não há serviço externo de tradução durante o uso.
- `app/helpers.php`: `t()`, `label_t()`, seletor e configuração segura para o navegador.
- `public/assets/js/i18n.js`: mensagens dinâmicas, parâmetros e plural. A interface PHP usa `e(t(...))` e HTML dinâmico deve escapar os valores interpolados.
- Tutoriais usam as mesmas traduções, preservando IDs de módulo/etapa, versão e progresso por usuário.

Os catálogos iniciais foram preparados com Gemini 4 Argon High, pela skill equipe-llm. O Codex integrou e revisou a cobertura, preservação de placeholders e testes. Isso não substitui a homologação terminológica das áreas operacionais.

## Manutenção

Ao adicionar um texto, use `t('Texto original')` e inclua a mesma chave nos dois catálogos. Para conteúdo variável, prefira `t('Passo {current} de {total}', [...])`. Não concatene uma frase em fragmentos quando a estrutura mudar entre idiomas.

Não passe dados de usuários pela tradução: nome, empresa, placa, documento, endereço, comentário e conteúdo de anexos devem permanecer como cadastrados. Use `label_t()` apenas para rótulos de enumerações conhecidas. Nunca traduza values, IDs, seletores CSS, rotas, SQL ou chaves da API. Não aplique substituição global ao HTML final ou ao DOM já preenchido.

Uma chave ausente mantém o texto original em português. Os arquivos `view-strings.json` e `js-strings.json` são inventários de manutenção; o teste de cobertura também inclui os procedimentos dos tutoriais.

## Verificações e pendências

Testes locais: seleção válida/inválida, cookie, GET versus POST, fallback, parâmetros, preservação de nomes e enums, plural e escape no JavaScript, templates reais de login e pessoas, central de tutoriais e exibição em celular. Os testes usam dados fictícios e não acessam o banco de produção.

Pendências: homologar termos com Portaria/SSMA/Segurança Patrimonial e atualizar a instalação publicada após o envio ao GitHub. A homologação deve contemplar estados com registros, alertas dinâmicos e integrações habilitadas. Mensagens externas ou texto livre que não constam do catálogo permanecem no idioma de origem.

Pontos de melhoria: ampliar frases parametrizadas de avisos com dados variáveis e incorporar novas traduções ao mesmo processo de revisão de cada mudança de interface. Avaliar preferência por conta somente se a operação precisar compartilhá-la entre equipamentos.
