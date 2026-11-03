# API de consulta para BI

## Contrato técnico da versão v1

A API consulta os dados locais do Salinas Access Gate por HTTPS. As rotas de negócio aceitam
somente GET. Não há operação de alteração de pessoas, acessos, solicitações ou chamados.
Uso do token, limitador e auditoria são gravados internamente a cada consulta.

Autenticação exclusiva pelo cabeçalho `Authorization: Bearer <token>`. Um parâmetro `?token=`
não autentica e nunca aparece no link da próxima página. Não há autorização por cookie, sessão
do usuário ou CSRF. As respostas são JSON UTF-8, com `Cache-Control: no-store` e sem CORS.
Consulte a pendência de integração ao final antes de liberar esta versão.

Cada token tem um nome, um ou mais escopos e validade opcional. O token tem o formato `sag_`
seguido de 40 caracteres alfanuméricos aleatórios. A criação retorna o segredo uma vez;
o banco guarda somente seu SHA-256 e um prefixo de oito caracteres. Não é possível recuperá-lo
na listagem. Para substituição, crie outro token, atualize o consumidor e revogue o anterior.

### Endpoints e escopos

Todos os caminhos abaixo começam com `/api/v1/`.

| Caminho | Escopo | Conteúdo e granularidade |
| --- | --- | --- |
| `status` | token válido | Um registro com `versao: v1` e a lista de escopos desse token |
| `acessos` | `acessos` | Um registro por movimentação; id, seq, sentido, data/hora, local, pessoa, ids de veículo e solicitação |
| `presenca` | `presenca` | Uma pessoa por local onde o último movimento é entrada, conforme a view de presença existente |
| `solicitacoes` | `solicitacoes` | Uma solicitação de acesso; pessoa, criador, área de autorização, local, tipo, origem, status e datas |
| `chamados` | `chamados` | Um chamado; título, área de serviço, categoria, solicitante/atendente, prioridade, status, datas e SLA |
| `chamados/avaliacoes` | `chamados` | Uma avaliação por chamado; nota, data, número, área e categoria, sem comentário ou avaliador |
| `cadastros/locais` | `cadastros` | Um local; id, nome, tipo, ativo e datas |
| `cadastros/areas` | `cadastros` | Uma área de serviço; id, nome, slug, ativo, padrões de SLA e datas |
| `cadastros/categorias` | `cadastros` | Uma categoria de serviço; id, área, categoria pai, nome, ativo, padrões de SLA e datas |
| `cadastros/pessoas-resumo` | `cadastros` | Uma combinação categoria/empresa; contagem de pessoas, incluindo inativas, e datas mínima/máxima do grupo |

Os dois últimos endpoints completam os dados previstos no escopo `cadastros`.
`solicitacoes.area_id` referencia as áreas de autorização da portaria (`areas`), enquanto
`chamados.area_id` e `cadastros/areas` referenciam `servico_areas`. São cadastros distintos.

Pessoas aparecem apenas pelos campos `pessoa_id`, `pessoa_nome`, `pessoa_categoria` e
`pessoa_empresa`. Em solicitações ainda sem pessoa cadastrada, o id pode ser nulo e os demais
campos usam os dados do visitante. Solicitante e atendente aparecem por id e nome. Não são
exportados CPF, documento do visitante, telefone, e-mail, fotos, anexos, observações, descrição
de chamado, respostas de formulário, comentários de avaliação ou tokens externos.
O título do chamado é um campo permitido; a equipe deve evitar inserir dados sensíveis nele.

### Parâmetros e paginação

| Parâmetro | Regra |
| --- | --- |
| `pagina` | Inteiro positivo, padrão 1, máximo 1.000.000 |
| `por_pagina` | Inteiro de 1 a 5000, padrão 500; valores fora do limite são recusados |
| `desde` | Limite inicial inclusivo: `YYYY-MM-DD` ou ISO com segundos, por exemplo `2026-11-03T08:00:00-04:00` |
| `ate` | Data simples inclui todo o dia; um instante ISO é limite exclusivo |
| `alterado_desde` | Limite inclusivo de atualização; aceita os mesmos formatos |

Um instante ISO pode conter frações de segundo e `Z` ou deslocamento. Sem deslocamento,
usa o fuso da aplicação. Os parâmetros são convertidos ao fuso configurado do banco e passados
como valores preparados. Em URLs, codifique `+` como `%2B` nos deslocamentos positivos.
Datas impossíveis, intervalos invertidos, arrays e paginação inválida retornam 400.

`desde`/`ate` filtram `data_hora` em acessos, `entrada_em` em presença e `created_at` nos demais
recursos. No resumo de pessoas, `created_at` é a menor data de criação do grupo. Para filtrar
visitas pela `data_prevista`, importe o período necessário e filtre essa coluna no BI.
`status` valida os parâmetros, mas não aplica filtros de data ao registro de identidade.

O incremental considera a última alteração do registro e dos cadastros usados nos nomes
exportados. Acessos são imutáveis, mas uma correção no nome da pessoa/local pode fazê-los
reaparecer. Em avaliações, também entram alterações do chamado/área/categoria.

```json
{
  "dados": [{"id": "...", "sentido": "entrada", "data_hora": "2026-11-03T08:00:00-04:00"}],
  "pagina": 1,
  "por_pagina": 500,
  "total": 1250,
  "proxima": "https://access-gate.salinasgold.com.br/api/v1/acessos?pagina=2&por_pagina=500"
}
```

`total` representa o total filtrado; `dados` contém somente a página solicitada. A última
página tem `proxima: null`. Uma página após o final retorna `dados: []`. A ordenação tem
desempate por id/seq. As datas com horário usam ISO 8601 no fuso da aplicação; `data_prevista`
é uma data de calendário `YYYY-MM-DD`. Valores ausentes permanecem nulos.
Configure `APP_URL` com a URL HTTPS pública e o caminho base correto para gerar `proxima`.

### SLA de chamados

Além das datas persistidas, `chamados` devolve `prazo_solucao_efetivo`, `sla_resposta_status` e
`sla_solucao_status`. Os estados usam as regras existentes: `no_prazo`, `vencendo`, `vencido`;
sem prazo configurado, o respectivo estado é nulo. O prazo efetivo considera pausas e o
calendário copiado no chamado. Os snapshots de calendário e primeira resposta são usados
apenas no cálculo e não são publicados. Também são expostos `segundos_pendente`,
`pendente_desde`, `prazo_prorrogado_min` e `fechado_automaticamente`.

### Atualização e consistência no BI

- Faça carga completa de presença: saídas removem pessoas do resultado e não produzem tombstones.
- Faça carga completa do resumo por categoria/empresa: troca de empresa/categoria ou exclusão
  pode reduzir/eliminar um grupo, sem um registro de remoção.
- No incremental de chamados, atualize por id e recalcule periodicamente os chamados abertos:
  o relógio pode mudar o estado do SLA sem modificar `updated_at`.
- Para os demais recursos, combine `alterado_desde` com sobreposição da janela e deduplicação
  por id; em avaliações, a chave é `chamado_id`. Nomes relacionados podem atualizar registros antigos.
- As páginas são consultas independentes. Não há snapshot entre páginas, cursor ou garantia
  de captura de exclusões. Feche uma janela de datas para extrações extensas e reconcilie
  periodicamente com carga completa. O schema atual não oferece um feed de remoções.

### Erros, limites e auditoria

| HTTP | Significado |
| --- | --- |
| 200 | Consulta concluída, inclusive resultado vazio |
| 400 | Parâmetros inválidos |
| 401 | Token ausente, inválido, expirado ou revogado; mensagem genérica |
| 403 | Token válido sem o escopo exigido |
| 404 | Recurso de API inexistente |
| 405 | Método diferente de GET; cabeçalho `Allow: GET` |
| 429 | Limite de 120 consultas por token em janela móvel de um minuto; aguarde `Retry-After: 60` |
| 500 | Consulta indisponível; somente uma referência, sem SQL ou dados internos |

O limite é compartilhado entre endpoints. O bloqueio por token no banco serializa contagem
e incremento do `RateLimiter`. O último uso e IP são atualizados no máximo uma vez por minuto.
Auditoria: `API_TOKEN_CRIAR`, `API_TOKEN_REVOGAR` e `API_CONSULTA`; esta última contém apenas id
do token, endpoint, quantidade de linhas e status HTTP. Não registra filtros, segredo ou dados
de resultado. Requisições sem identidade válida não geram `API_CONSULTA`.

O serviço administrativo oferece:

```php
ApiTokens::criar(string $nome, array $escopos, ?int $validadeDias, string $criadoPor): array; // id, token
ApiTokens::listar(): array; // sem hash/token; escopos como lista
ApiTokens::revogar(string $id, string $por): void;
ApiTokens::autenticar(string $tokenClaro, string $escopo): ?array; // id e escopos
```

Nomes têm até 120 caracteres; validade, quando fornecida, fica entre 1 e 3650 dias.
A tela pode oferecer as opções 30/90/180/365 dias. `autenticar($token, '')` autentica somente
a identidade; o controller verifica separadamente o escopo para distinguir 401 de 403.

### Exemplos curl

Defina `SAG_BI_TOKEN` pelo mecanismo de segredos do consumidor. Os exemplos usam uma variável
e não incluem uma credencial real. Evite execução com rastreamento de comandos ou logs verbosos.

```sh
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $SAG_BI_TOKEN" \
  'https://access-gate.salinasgold.com.br/api/v1/status'

curl --fail-with-body --silent --show-error --get \
  -H "Authorization: Bearer $SAG_BI_TOKEN" \
  --data-urlencode 'desde=2026-11-01' \
  --data-urlencode 'ate=2026-11-03' \
  --data-urlencode 'alterado_desde=2026-11-02T00:00:00-04:00' \
  --data-urlencode 'pagina=1' --data-urlencode 'por_pagina=500' \
  'https://access-gate.salinasgold.com.br/api/v1/acessos'
```

### Exemplo Power BI / Power Query

Crie um parâmetro de texto `TokenBi` e forneça o segredo no ambiente controlado do responsável
pelo BI. Um parâmetro M não é um cofre: restrinja o acesso ao PBIX e não distribua cópias com
o token preenchido. Para produção com governança central de credenciais, avalie um conector
dedicado. Não use `ApiKeyName`, pois essa opção transmite a chave na URL.

O exemplo usa uma base fixa, `RelativePath`, `Query` e `Headers`, conforme
[Web.Contents](https://learn.microsoft.com/en-us/powerquery-m/web-contents), e percorre páginas
com [List.Generate](https://learn.microsoft.com/en-us/powerquery-m/list-generate).
Selecione credencial da fonte **Anônima** para que o cabeçalho seja informado pela consulta M;
a API continua exigindo Bearer. Homologue a atualização no Desktop e no serviço antes de
agendar: esse ambiente não executou Power BI.

```powerquery
let
    Recurso = "api/v1/acessos",
    Filtros = [desde = "2026-11-01", ate = "2026-11-03"],
    LerPagina = (Numero as number) as record =>
        Json.Document(Web.Contents(
            "https://access-gate.salinasgold.com.br/",
            [
                RelativePath = Recurso,
                Query = Record.Combine({Filtros, [
                    pagina = Text.From(Numero), por_pagina = "500"
                ]}),
                Headers = [Authorization = "Bearer " & TokenBi, Accept = "application/json"],
                Timeout = #duration(0, 0, 2, 0)
            ]
        )),
    Paginas = List.Generate(
        () => [Numero = 1, Resposta = LerPagina(1)],
        each [Resposta] <> null,
        each if [Resposta][proxima] = null
            then [Numero = [Numero], Resposta = null]
            else [Numero = [Numero] + 1, Resposta = LerPagina([Numero] + 1)],
        each [Resposta][dados]
    ),
    Linhas = List.Combine(Paginas),
    Resultado = if List.IsEmpty(Linhas) then #table({}, {}) else Table.FromRecords(Linhas)
in
    Resultado
```

Ajuste os tipos das colunas no modelo, usando `datetimezone` para datas com horário. Não remova
o deslocamento antes de decidir o fuso de apresentação. O consumidor deve tratar 429 com espera
e repetir a mesma página; não interprete falha de autenticação como tabela vazia.

## Pendências

- Integração do requisito sem sessão/cookies em `public/index.php` e `app/bootstrap.php`,
  cujo ajuste mínimo foi solicitado ao usuário por estarem fora da lista de arquivos da etapa.
- Revisão pelo gerente, integração da tela administrativa, permissões, traduções e tutorial.
- Aplicar a migração 014 em MariaDB descartável e validar SQL/FKs, concorrência e dados reais
  anonimizados; os testes locais usam PDO simulado e front controller HTTP real.
- Homologar recebimento do cabeçalho Authorization no Apache/PHP-FPM e Power BI Desktop/serviço.

O ajuste proposto para integração é limitado a dispensar `I18n::init()` no bootstrap quando
`Http::path()` começa por `/api/v1/` e dispensar as duas chamadas de `Session::start()` no
front controller para esse mesmo prefixo, inclusive no tratamento de POST excedido. A API
formata seu JSON diretamente para não disparar a inicialização de idioma/cookies. Antes de
liberar, confirme que `php tests/api.php` passa também pelas duas últimas verificações
(nenhum `Set-Cookie`, sessão PHP não iniciada).

## Pontos de melhoria

- Avaliar índices para filtros incrementais com EXPLAIN e volume representativo antes de implantá-los.
- Avaliar cursores, tombstones e watermark de extração para cargas maiores.
- Formalizar responsável, finalidade, escopos mínimos, rotação e retenção dos dados no BI.
