# Integração Infleet — veículos, motoristas e percursos

Data: 14/10/2026 · Salinas Gold Mineração LTDA · TI

Implementação preparada para sincronizar cadastros e consultar viagens com trajeto GPS no mapa.
A integração vem desativada e ainda depende de homologação com o token da conta Salinas.

## Configuração

A API é GraphQL, acessada pelo servidor PHP em `https://api.infleet.com.br/v1/graphql` com `Authorization: Bearer <token>`.
Solicite o token ao suporte Infleet, com acesso de leitura aos veículos, motoristas, viagens e posições da organização Salinas.
Solicite também o ID da organização. Não envie o token pelo GitHub, por chamados públicos ou pelo navegador.

Requisitos adicionais: extensão PHP `curl` e acesso HTTPS de saída à Infleet. A imagem Docker do projeto já prevê curl.
O token fica somente no `.env` do servidor ou, no Docker, em `docker/app.env`, fora do versionamento.

```dotenv
INFLEET_ENABLED=true
INFLEET_TOKEN=preencher_somente_no_servidor
INFLEET_ORGANIZATION_ID=id_confirmado_pela_infleet
INFLEET_TIMEOUT=20
INFLEET_MAX_RECORDS=10000
```

Após atualizar os arquivos, executar no ambiente de homologação:

```bash
php bin/console.php migrar
php bin/console.php infleet-testar
php bin/console.php infleet-sincronizar
```

No Docker, executar os comandos usando `docker compose exec -T app php bin/console.php ...`.
Alterações em `docker/app.env` exigem recriar o serviço app (`docker compose up -d --force-recreate app`).
A migração `004_infleet.sql` cria três tabelas auxiliares, com vínculos aos cadastros existentes.
Antes da atualização do ambiente real, faça o backup de rotina do banco e da aplicação.

## Uso

O menu **Cadastros → Infleet** é acessível a Admin/TI e Supervisor. Só Admin/TI pode sincronizar e conferir motoristas.
A tela mostra modelo, cor local, odômetro, vínculo ao cadastro, situação e data da sincronização.
Listagens locais têm páginas de 50 registros. A importação lê todas as páginas da API dentro do limite configurado.

Em **Ver percursos**, selecione o veículo e as datas/horas. O limite de 7 dias por consulta é uma proteção da aplicação,
não um limite confirmado da API. Para consultar períodos mais antigos, selecione outra janela de até 7 dias;
a disponibilidade histórica depende do contrato e da retenção Infleet.
O histórico exibe início, fim, motorista identificado na viagem e distância informada.
**Ver trajeto** restringe o mapa à viagem; **Mostrar todo o período** volta à consulta inteira.

O mapa usa Leaflet 1.9.4 servido localmente e imagens cartográficas OpenStreetMap carregadas pelo navegador.
Somente essa página permite imagens de `https://tile.openstreetmap.org` na CSP. Scripts continuam locais.
O provedor cartográfico recebe requisições dos tiles da área visualizada, sem token Infleet, nome ou CPF.
Não há download em massa ou pré-carregamento de mapas. O mapa precisa de internet para o fundo cartográfico;
se os tiles falharem, o traçado GPS continua disponível. Pontos inválidos e fora do período são descartados.
Pontos são ordenados por horário UTC; não há ligação entre viagens ou lacunas acima de 10 minutos.
Linhas entre pontos são visualização das amostras GPS e não comprovação de trajeto contínuo.

## Google Maps (opcional)

Na tela de percursos, o campo **Mapa** permite alternar OpenStreetMap e Google Maps quando a chave estiver configurada.
O Google Maps oferece controles de ruas/satélite, Street View e tela cheia, com os pontos e segmentos GPS consultados na Infleet.
Não há cálculo de rota pelo Google: as linhas representam as amostras recebidas, interrompendo ligações em lacunas acima de 10 minutos e entre viagens.
Os atalhos **Abrir ponto inicial/final no Google Maps** usam as coordenadas da seleção atual e funcionam sem chave de API.
Esses atalhos abrem um local, não um percurso estimado pelo Google.

Para ativar o mapa incorporado:

1. Criar ou selecionar um projeto da Salinas no Google Cloud e habilitar faturamento.
2. Habilitar **Maps JavaScript API** e criar uma chave exclusiva de navegador para esta aplicação.
3. Em **Restrições do aplicativo**, escolher **Sites** e autorizar apenas a origem utilizada pela aplicação, por exemplo `https://accessgate.mycode.app.br/*`.
4. Em **Restrições da API**, autorizar exclusivamente **Maps JavaScript API**. A chave do navegador é visível na página; as restrições impedem uso indevido em outros sites/APIs.
5. Configurar no `.env` do servidor ou `docker/app.env`:

```dotenv
GOOGLE_MAPS_ENABLED=true
GOOGLE_MAPS_BROWSER_KEY=chave_de_navegador_restrita
```

No Docker, recriar o app após alterar o arquivo de ambiente. Homologar no domínio autorizado, conferindo mapas de ruas/satélite,
zoom, troca de provedor, leitura de uma viagem e abertura das coordenadas no Google Maps.
Para voltar ao OpenStreetMap por padrão, configurar `GOOGLE_MAPS_ENABLED=false`.

A API Google é carregada somente ao visualizar pontos no provedor Google e apenas uma vez por página.
Trocar viagens ou alternar provedores reutiliza o mapa carregado. Falha de rede ou de autorização retorna ao OpenStreetMap com aviso.
A chave Infleet, CPFs e nomes de motoristas não são enviados pelo aplicativo ao provedor cartográfico.
O navegador executa a API Google e renderiza as coordenadas; o uso desse serviço deve constar nos termos/aviso de privacidade da aplicação.
A aplicação preserva as marcas e atribuições dos provedores nos mapas.

A CSP usa nonce aleatório por resposta para scripts/estilos, seguindo a orientação Google.
Permissões de recursos Google e `unsafe-eval` ficam limitadas à página de percursos, quando a integração Google está habilitada e tem chave.
As demais telas mantêm a CSP existente. A validação real da API Google está pendente da configuração do projeto e da chave restrita.
Não foi criado projeto Google Cloud nem habilitado faturamento nesta implementação.
Os cinco testes automatizados de mapas passaram. A validação em navegador isolado conferiu a troca Google/OpenStreetMap,
nonce/CSP na resposta PHP, falha de autenticação, links de coordenadas e renderização em celular, com a API Google simulada.

## Origem e correspondência dos campos

| Informação | Campo Infleet | Tratamento no Access Gate |
|---|---|---|
| Identidade do veículo | `Vehicle.id` | Vínculo externo persistente |
| Placa | `plate` | Correspondência inicial com placa local normalizada; mudanças posteriores exigem conferência |
| Modelo | `brand` + `model` | Atualiza `veiculos.marca_modelo` com auditoria quando houver mudança |
| Cor | Não consta no tipo público `Vehicle` consultado | Preserva `veiculos.cor`; confirmar disponibilidade com suporte |
| Quilometragem | `odometer` | Armazena leitura decimal, mantendo zero e nulo distintos; unidade precisa de confirmação |
| Identidade do motorista | `Driver.id` | Vínculo externo persistente |
| Nome do motorista | `name` | Metadado Infleet; nome local já conferido é preservado |
| CPF | `cpf` | Valida dígitos, cifra com libsodium e associa por HMAC ao CPF local; tela mostra máscara |
| Situação do motorista | `active` | Situação remota informativa; não muda o ativo local |
| Viagem | `Trip.id`, `startedAt`, `finishedAt`, `distanceTraveled`, `driver` | Consulta sob demanda, sem gravar posições GPS no banco local |
| Trajeto | `listVehiclePositions`: `latitude`, `longitude`, `fixTime`, `valid` | Mapa do período ou da viagem selecionada |

Não se usa o motorista atualmente associado ao veículo para inferir quem dirigiu uma viagem passada.
Não são importados biometria, fotografias, senhas ou dados de documentos não solicitados.
Número de CNH não comprova validade de documento; a documentação de acesso continua no fluxo local.
Até a confirmação das unidades, odômetro e distância são exibidos na unidade original, sem rótulo de km ou conversão presumida.

## Regras de sincronização e conferência

1. As consultas de veículos e motoristas são filtradas pela organização e as respostas são conferidas contra esse ID.
2. As duas listagens completas são lidas antes da gravação. Contagens divergentes, IDs repetidos, erros HTTP/GraphQL e respostas parciais impedem a atualização.
3. As alterações usam transação. Uma falha de validação desfaz também os registros já processados naquela execução.
4. Um lock no banco impede duas sincronizações simultâneas; repetir a importação mantém os vínculos sem duplicar cadastros.
5. Novos veículos com placa e tipo compatíveis são criados **inativos**. Admin/TI confere tipo, cor, empresa, comissionamento e ativação no cadastro local.
6. Veículos com placa ou tipo incompatível e identidades externas com a mesma placa ficam sem vínculo. Cadastre/corrija o veículo local e sincronize novamente.
7. Motoristas com CPF válido são associados à pessoa existente por correspondência exata. Não se associa por nome.
8. Motoristas novos ficam pendentes até **Conferir e cadastrar**, com escolha explícita de categoria e empresa. A nova pessoa fica **inativa** para validar documentos e acesso.
9. CPF ausente/inválido, duplicidade ou identidade alterada exigem correção/conferência. Não há associação automática por aproximação.
10. Registros que sumirem da listagem completa ficam marcados como ausentes, com a data da última leitura preservada. Não são excluídos nem desativados na portaria.

Comissionamento, categoria, documentos, verificações SSMA, solicitações aprovadas, ativo local e registros históricos de acesso continuam no fluxo local.
Uma situação ativa na Infleet não representa autorização de entrada na mina.
A sincronização e as consultas de percurso são registradas na auditoria, sem token ou CPF em texto puro.

## Agendamento após homologação

Exemplo proposto para sincronizar a cada 15 minutos em instalação nativa (ajustar o caminho ao servidor):

```cron
*/15 * * * * cd /caminho/salinas-access-gate && /usr/bin/php bin/console.php infleet-sincronizar >> storage/logs/infleet-cron.log 2>&1
```

Esse agendamento não foi instalado. Confirme os limites de chamadas antes de adotá-lo.
O uso da portaria consulta o banco local e não depende da disponibilidade imediata da API Infleet.

## Validação

- `php tests/infleet.php`: testa o cliente com respostas simuladas, sem token real. Executado também no CI PHP 8.2/8.5.
- `node --test tests/infleet_maps.test.cjs`: verifica carregamento único/nonce, segmentação, troca de provedor, links com coordenadas e falhas Google com APIs simuladas, sem consumo da API Google.
- `tests/infleet_database.php`: recusa execução em banco real ou cópia com `.env`; requer banco descartável `sag_infleet_test`, previamente migrado, e `INFLEET_TEST_DB=1`.
- Validação local realizada: PHP 8.5.11, MariaDB 11.8.3, aplicação e reaplicação das quatro migrações; cliente com respostas simuladas; sincronização repetida, rollback, CPF cifrado e identidade duplicada; interface desktop e celular e permissões Admin/TI, Supervisor e Porteiro.
- Mapa validado em navegador isolado com viagens/posições simuladas e tiles OpenStreetMap; nenhuma consulta autenticada à Infleet foi feita. A execução do CI PHP 8.2/8.5 no GitHub depende da publicação da alteração.
- Homologação real: conferir veículos e motoristas com a conta Infleet, rodar duas sincronizações e confirmar ausência de duplicatas; comparar ao menos uma viagem e seus pontos GPS na plataforma de origem.

## Pendências

- Solicitar token de leitura e ID da organização Salinas.
- Confirmar acesso às queries `countVehicles`, `listVehicles`, `countDrivers`, `listDriversPaginated`, `trips` e `listVehiclePositions`.
- Confirmar unidades, precisão e atualização de `odometer` e `distanceTraveled`; confirmar disponibilidade da cor por API.
- Confirmar limite de chamadas, tamanho de páginas, retenção histórica e escopo de veículos/motoristas.
- Homologar com dados reais e conferir novos cadastros antes da ativação.
- Configurar projeto Google Cloud, faturamento, Maps JavaScript API e chave restrita ao domínio; validar o mapa Google real.
- Definir cotas/alertas de consumo Google e documentar o uso do provedor no aviso de privacidade e nos termos da aplicação.
- Publicar a alteração no GitHub e implantar somente após revisar e homologar.

## Pontos de melhoria

- Após confirmar unidades, exibir quilometragem com rótulo e conversão corretos, diferenciando idade da leitura e horário da sincronização.
- Monitorar atraso/falha da sincronização no Zabbix e definir responsável por tratar vínculos pendentes.
- Se o volume crescer, executar sincronização exclusivamente por job/CLI e usar provedor cartográfico dimensionado ao uso interno.
- Avaliar cruzamento de entrada/saída com viagens depois que os vínculos estiverem homologados.

## Fontes verificadas em 14/10/2026

- [Infleet: autenticação e endpoint](https://docs.api.infleet.com.br/boas-vindas)
- [Vehicle](https://docs.api.infleet.com.br/types/Vehicle), [listVehicles](https://docs.api.infleet.com.br/queries/listVehicles)
- [Driver](https://docs.api.infleet.com.br/types/Driver), [listDriversPaginated](https://docs.api.infleet.com.br/queries/listDriversPaginated)
- [Trip](https://docs.api.infleet.com.br/types/Trip), [trips](https://docs.api.infleet.com.br/queries/trips)
- [Position](https://docs.api.infleet.com.br/types/Position), [ListVehiclePositionsFilterInput](https://docs.api.infleet.com.br/types/ListVehiclePositionsFilterInput)
- [Leaflet: versão e distribuição](https://leafletjs.com/download.html), [OpenStreetMap: política de tiles](https://operations.osmfoundation.org/policies/tiles/)
- [Google Maps: configuração de projeto/chave](https://developers.google.com/maps/documentation/javascript/get-api-key), [restrições da chave](https://developers.google.com/maps/api-security-best-practices)
- [Google Maps: carregamento da API](https://developers.google.com/maps/documentation/javascript/load-maps-js-api), [CSP](https://developers.google.com/maps/documentation/javascript/content-security-policy), [links com coordenadas](https://developers.google.com/maps/documentation/urls/get-started), [políticas e atribuições](https://developers.google.com/maps/documentation/javascript/policies)
