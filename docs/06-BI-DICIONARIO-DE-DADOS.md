# Camada de BI e dicionário de dados

**Documento:** MD-TI-SIS-CBA-006 · Rev. 00 · Salinas Access Gate
**Banco:** MariaDB 11.8 (Docker) / 10.11+ · **Migração:** `database/migrations/004_bi.sql`
**Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 1. Objetivo

Deixar os dados do controle de acesso prontos para Power BI, Metabase e Excel, com três garantias:

1. **Usuário próprio e somente leitura** (`sag_bi`). Ele não enxerga as tabelas operacionais, só as views `bi_*`.
2. **Dados estruturados** em modelo estrela (dimensões + fatos), com nomes de colunas em português e chaves claras.
3. **Banco comentado**: toda tabela e coluna operacional tem `COMMENT`, e cada view de BI está descrita na tabela `bi_dicionario`.

## 2. Arquitetura

```
Power BI / Metabase / Excel
        │  usuário sag_bi (SELECT apenas, 5 conexões, consulta ≤ 120 s)
        ▼
  views bi_dim_* · bi_fato_* · bi_presenca_atual · bi_agg_diario_local · bi_dicionario
        │  SQL SECURITY DEFINER (a view lê as tabelas; o sag_bi não)
        ▼
  tabelas operacionais (pessoas, registros_acesso, solicitacoes_acesso, ...)
```

| Regra | Como é garantida |
|---|---|
| LGPD / minimização | As views não trazem CPF, RG, telefone, e-mail, tokens, hashes de senha nem observações livres |
| Somente leitura | `GRANT SELECT` objeto a objeto. Testado: `SELECT` em `pessoas`, `DELETE` e `CREATE` são negados |
| Proteção da portaria | `MAX_USER_CONNECTIONS 5` e `MAX_STATEMENT_TIME 120` no usuário de BI |
| Fuso | “Agora” e “hoje” nas views usam `CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-04:00')`, que não depende do fuso da sessão do BI |
| Estrutura intacta | A migração 004 só acrescenta comentários. A comparação de `SHOW CREATE TABLE` antes e depois é idêntica no MariaDB 10.11 e no 11.8 |

## 3. Criar o usuário de BI

### 3.1 Docker (automático)

O `docker/instalar.sh` cria ou atualiza o `sag_bi` em toda instalação e atualização:

- A senha é gerada uma vez em `/opt/salinas-access-gate/docker/bi.env` (`chmod 600`) e copiada a cada backup. Guarde-a no cofre.
- As permissões são refeitas a cada atualização, então views novas entram automaticamente.

**Banco acessível pela rede (opcional).** Por padrão o MariaDB **não** é publicado. Para o Power BI ou o Metabase em outra máquina:

```bash
sudo SAG_BI_PORTA=3306 bash /tmp/sag/docker/instalar.sh /tmp/sag          # publica em 0.0.0.0:3306
sudo SAG_BI_PORTA=3306 SAG_BI_REDE=192.168.4.5 bash /tmp/sag/docker/instalar.sh /tmp/sag   # só na interface da LAN
sudo SAG_BI_PORTA=0 bash /tmp/sag/docker/instalar.sh /tmp/sag             # volta a não publicar
```

- A escolha fica gravada no `.env` da pasta, via `COMPOSE_FILE` com `docker-compose.bi.yml`. Atualizações seguintes mantêm a escolha.
- Com a porta publicada, o instalador remove o `root@'%'`. O root passa a entrar só por dentro do container, pelo socket local.
- **Firewall:** o Docker ignora o UFW. No Sophos, libere a porta 3306 do servidor apenas para o IP do gateway de BI ou do servidor do Metabase.

### 3.2 Sem Docker (XAMPP, Virtualmin)

1. Gere o SQL: `php bin/console.php bi-sql --host=192.168.4.% > bi.sql`. O padrão de `--host` é `%`.
2. Troque `TROQUE_ESTA_SENHA` por uma senha forte.
3. Execute como root no HeidiSQL, DBeaver ou `mariadb -uroot -p < bi.sql`.

Rodar de novo é seguro. O script revoga e concede as permissões outra vez, e deve ser reexecutado após cada migração que crie views `bi_*`.

## 4. Conectar

| Ferramenta | Como |
|---|---|
| **Power BI Desktop** | *Obter dados* → *Banco de dados MySQL* (exige o MySQL Connector/NET) ou *ODBC* com o MariaDB Connector/ODBC. Servidor `192.168.4.5:3306`, banco `salinas_access_gate`, usuário `sag_bi`. Para atualizar o relatório no serviço do Power BI, use o **Gateway on-premises** na rede interna |
| **Metabase** | *Admin* → *Bancos de dados* → *MariaDB*, com os mesmos dados. Marque “somente leitura” e sincronização diária |
| **Excel** | *Dados* → *Obter dados* → *Do Banco de Dados MySQL* (Power Query), com o mesmo conector do Power BI |

Primeira consulta para conferir: `SELECT objeto, descricao, granularidade FROM bi_dicionario WHERE coluna = '*';`

## 5. Modelo estrela sugerido

```
bi_dim_local ──┐            ┌── bi_dim_pessoa
bi_dim_veiculo ┼─ bi_fato_movimentacao ─┤
bi_dim_usuario ┘   (porteiro_id)         └── bi_fato_permanencia / bi_presenca_atual

bi_dim_area ── bi_fato_solicitacao ── bi_fato_verificacao_ssma
bi_dim_pessoa ── bi_fato_documento
```

- Relacione as tabelas pelas colunas `*_id`, de um para muitos, com a dimensão do lado “um”.
- Crie no BI uma tabela calendário (`CALENDARAUTO()` no Power BI) e ligue-a às colunas `data`, `data_entrada`, `data_criacao` e `data_verificacao`.
- Para indicadores do dia, use `bi_agg_diario_local`, que é mais leve que agregar `bi_fato_movimentacao`.

## 6. Consultas de exemplo

```sql
-- Entradas por dia e categoria nos últimos 30 dias
SELECT data, local_nome, entradas_colaboradores, entradas_visitantes, entradas_terceiros
  FROM bi_agg_diario_local WHERE data >= CURDATE() - INTERVAL 30 DAY ORDER BY data;

-- Permanência média (min) de visitantes e terceiros por empresa no mês
SELECT pessoa_empresa, COUNT(*) AS visitas, ROUND(AVG(permanencia_min)) AS media_min
  FROM bi_fato_permanencia
 WHERE situacao = 'concluida' AND pessoa_categoria IN ('visitante','terceiro')
   AND data_entrada >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
 GROUP BY pessoa_empresa ORDER BY visitas DESC;

-- Falhas de registro (entrada sem saída) por local: indicador de qualidade da portaria
SELECT local_nome, COUNT(*) AS sem_saida FROM bi_fato_permanencia
 WHERE situacao = 'sem_saida_registrada' GROUP BY local_nome;

-- SLA de aprovação das solicitações por área (horas)
SELECT area_nome, COUNT(*) AS decididas, ROUND(AVG(minutos_ate_decisao) / 60, 1) AS horas_media
  FROM bi_fato_solicitacao WHERE decidido_em IS NOT NULL GROUP BY area_nome;

-- Documentos de SSMA vencidos ou a vencer de quem está ativo (situação atual)
SELECT tipo, situacao, COUNT(*) FROM bi_fato_documento
 WHERE mais_recente_do_tipo = 1 AND pessoa_ativa = 1 GROUP BY tipo, situacao;
```

## 7. Comentários das tabelas operacionais

Os comentários aparecem no DBeaver, HeidiSQL e Workbench. Também podem ser consultados por SQL, com um usuário que tenha acesso às tabelas, como o DBA:

```sql
SELECT table_name, column_name, column_comment
  FROM information_schema.columns
 WHERE table_schema = 'salinas_access_gate' AND table_name NOT LIKE 'bi\_%'
 ORDER BY table_name, ordinal_position;
```

## 8. Manutenção

- **Nova view de BI:**
  1. Crie a migração `00X_*.sql` com `CREATE OR REPLACE VIEW bi_...`.
  2. Inclua as linhas da view no `bi_dicionario`.
  3. Atualize este documento.
  4. Confira com `php bin/console.php bi-verificar`, que falha se alguma coluna ficar sem descrição.
5. Rode `instalar.sh` (Docker) ou `bi-sql` (sem Docker) para conceder a permissão.
- **Nova coluna em tabela operacional:** declare o `COMMENT` já na migração.
- **Remoção de dados:** as tabelas `registros_acesso`, `audit_log`, `verificacoes_ssma` e `registro_anexos` são append-only. O BI nunca altera dados.

## 9. Validação desta revisão

- **Dicionário completo:** `bi-verificar` confirma 148 colunas em 13 objetos, todas descritas. O comando também roda no CI.
- **Estrutura intacta:** `SHOW CREATE TABLE` idêntico antes e depois da 004, sem contar os comentários, no MariaDB 10.11 e no 11.8. Os 10 triggers foram preservados.
- **Permanência:** pareamento conferido com casos de saída normal, entrada sem saída e pessoa ainda dentro.
- **Usuário de BI:** lê as 12 views e o dicionário. `pessoas`, `usuarios`, `vw_presenca_dentro`, `INSERT`, `DELETE` e `CREATE` são negados. O root remoto é recusado com a porta publicada.
- **CI:** o GitHub Actions instala do zero e repete os testes de permissão do `sag_bi`.

## 10. Dicionário das views (`bi_*`)

> Também disponível dentro do banco: `SELECT * FROM bi_dicionario ORDER BY objeto, ordem;`

### Dimensões

#### `bi_dim_local`

Dimensão de locais/portarias. **Grão:** uma linha por local.

| Coluna | Tipo | Descrição |
|---|---|---|
| `local_id` | uuid | Chave do local (UUID). |
| `local_nome` | texto | Nome do local/portaria. |
| `local_tipo` | texto | mina ou escritorio. |
| `ativo` | inteiro | 1 = ativo. |

#### `bi_dim_area`

Dimensão de áreas e seus responsáveis. **Grão:** uma linha por área.

| Coluna | Tipo | Descrição |
|---|---|---|
| `area_id` | uuid | Chave da área (UUID). |
| `area_nome` | texto | Nome da área. |
| `responsavel_usuario_id` | uuid | Usuário responsável (liga em bi_dim_usuario). |
| `responsavel_nome` | texto | Nome do responsável. |
| `ativo` | inteiro | 1 = ativa. |

#### `bi_dim_pessoa`

Dimensão de pessoas, sem dados sensíveis (sem CPF, RG, telefone ou e-mail). **Grão:** uma linha por pessoa.

| Coluna | Tipo | Descrição |
|---|---|---|
| `pessoa_id` | uuid | Chave da pessoa (UUID). |
| `pessoa_nome` | texto | Nome completo. |
| `categoria` | texto | colaborador, visitante ou terceiro. |
| `empresa` | texto | Empresa. |
| `funcao` | texto | Cargo/função. |
| `matricula` | texto | Matrícula (colaboradores). |
| `possui_cpf` | inteiro | 1 = CPF cadastrado (o número não é exposto). |
| `ativo` | inteiro | 1 = cadastro ativo. |
| `cadastrado_em` | data | Data do cadastro. |

#### `bi_dim_veiculo`

Dimensão de veículos. **Grão:** uma linha por veículo.

| Coluna | Tipo | Descrição |
|---|---|---|
| `veiculo_id` | uuid | Chave do veículo (UUID). |
| `placa` | texto | Placa sem hífen. |
| `tipo` | texto | Tipo do veículo. |
| `marca_modelo` | texto | Marca e modelo. |
| `cor` | texto | Cor. |
| `empresa` | texto | Empresa responsável. |
| `comissionado_mina` | inteiro | 1 = comissionado para a mina. |
| `comissionamento_validade` | data | Validade do comissionamento. |
| `comissionamento_vigente` | inteiro | 1 = comissionado e dentro da validade hoje (UTC-4). |
| `ativo` | inteiro | 1 = ativo. |

#### `bi_dim_usuario`

Dimensão de usuários do sistema (porteiros, SSMA, supervisores, admins), sem e-mail e sem senha. **Grão:** uma linha por usuário.

| Coluna | Tipo | Descrição |
|---|---|---|
| `usuario_id` | uuid | Chave do usuário (UUID). |
| `usuario_nome` | texto | Nome. |
| `papel` | texto | admin, supervisor, ssma ou porteiro. |
| `seguranca_patrimonial` | inteiro | 1 = Segurança Patrimonial. |
| `auth_origem` | texto | local ou ldap (Active Directory). |
| `ativo` | inteiro | 1 = ativo. |
| `ultimo_login_em` | data/hora | Último login (UTC-4). |

### Fatos

#### `bi_fato_movimentacao`

Fato principal: movimentos de portaria (entradas e saídas). **Grão:** uma linha por movimento.

| Coluna | Tipo | Descrição |
|---|---|---|
| `registro_id` | uuid | Chave do movimento (UUID). |
| `seq` | inteiro | Ordem de gravação (desempate). |
| `data_hora` | data/hora | Data/hora do movimento (UTC-4). |
| `data` | data | Data do movimento (liga no calendário do BI). |
| `hora` | inteiro | Hora cheia (0–23). |
| `dia_semana` | inteiro | 1 = segunda ... 7 = domingo. |
| `dia_semana_nome` | texto | Nome do dia da semana. |
| `sentido` | texto | entrada ou saida. |
| `local_id` | uuid | Local (bi_dim_local). |
| `local_nome` | texto | Nome do local. |
| `pessoa_id` | uuid | Pessoa (bi_dim_pessoa); nulo quando só veículo. |
| `pessoa_nome` | texto | Nome da pessoa. |
| `pessoa_categoria` | texto | Categoria da pessoa. |
| `pessoa_empresa` | texto | Empresa da pessoa. |
| `veiculo_id` | uuid | Veículo (bi_dim_veiculo), quando houver. |
| `veiculo_placa` | texto | Placa do veículo. |
| `solicitacao_id` | uuid | Solicitação atendida (bi_fato_solicitacao), quando houver. |
| `destino` | texto | Destino informado. |
| `responsavel_interno_nome` | texto | Responsável interno (anfitrião). |
| `porteiro_id` | uuid | Usuário que registrou (bi_dim_usuario). |
| `porteiro_nome` | texto | Nome de quem registrou. |
| `qtd_fotos` | inteiro | Quantidade de fotos/anexos do movimento. |

#### `bi_fato_permanencia`

Permanência: cada entrada de pessoa pareada com a saída seguinte no mesmo local. **Grão:** uma linha por entrada de pessoa.

| Coluna | Tipo | Descrição |
|---|---|---|
| `registro_entrada_id` | uuid | Movimento de entrada. |
| `registro_saida_id` | uuid | Movimento de saída pareado (nulo se não houver). |
| `pessoa_id` | uuid | Pessoa. |
| `pessoa_nome` | texto | Nome. |
| `pessoa_categoria` | texto | Categoria. |
| `pessoa_empresa` | texto | Empresa. |
| `local_id` | uuid | Local. |
| `local_nome` | texto | Nome do local. |
| `veiculo_id` | uuid | Veículo usado na entrada. |
| `entrada_em` | data/hora | Data/hora da entrada (UTC-4). |
| `data_entrada` | data | Data da entrada. |
| `saida_em` | data/hora | Data/hora da saída (UTC-4); nulo se ainda dentro ou sem saída registrada. |
| `situacao` | texto | concluida (tem saída), em_andamento (ainda dentro) ou sem_saida_registrada (houve nova entrada sem saída — falha de registro). |
| `permanencia_min` | inteiro | Minutos entre entrada e saída; para em_andamento, até agora; nulo para sem_saida_registrada. |

#### `bi_presenca_atual`

Quem está dentro agora (último movimento da pessoa no local é uma entrada). **Grão:** uma linha por pessoa/local dentro.

| Coluna | Tipo | Descrição |
|---|---|---|
| `registro_id` | uuid | Movimento de entrada. |
| `pessoa_id` | uuid | Pessoa. |
| `pessoa_nome` | texto | Nome. |
| `pessoa_categoria` | texto | Categoria. |
| `pessoa_empresa` | texto | Empresa. |
| `veiculo_id` | uuid | Veículo da entrada. |
| `veiculo_placa` | texto | Placa. |
| `local_id` | uuid | Local. |
| `local_nome` | texto | Nome do local. |
| `local_tipo` | texto | mina ou escritorio. |
| `destino` | texto | Destino informado. |
| `entrada_em` | data/hora | Data/hora da entrada (UTC-4). |
| `minutos_dentro` | inteiro | Minutos desde a entrada até agora. |
| `acima_alerta_permanencia` | inteiro | 1 = acima do limite configurado em alerta_permanencia_horas (padrão 14 h). |

#### `bi_fato_solicitacao`

Solicitações de acesso de visitantes e terceiros, sem documento, contato ou token. **Grão:** uma linha por solicitação.

| Coluna | Tipo | Descrição |
|---|---|---|
| `solicitacao_id` | uuid | Chave da solicitação. |
| `criada_em` | data/hora | Data/hora de criação (UTC-4). |
| `data_criacao` | data | Data de criação. |
| `data_prevista` | data | Data prevista da visita. |
| `tipo` | texto | visitante ou terceiro. |
| `status` | texto | aguardando_ssma, pendente, aprovada, recusada, realizada ou expirada. |
| `origem` | texto | interna, link ou publica. |
| `atividade_risco` | inteiro | 1 = exige SSMA. |
| `area_id` | uuid | Área (bi_dim_area). |
| `area_nome` | texto | Nome da área. |
| `local_id` | uuid | Local previsto. |
| `local_nome` | texto | Nome do local. |
| `empresa` | texto | Empresa do visitante/terceiro. |
| `visitante_nome` | texto | Nome informado na solicitação. |
| `pessoa_id` | uuid | Pessoa cadastrada vinculada. |
| `responsavel_interno_id` | uuid | Anfitrião (bi_dim_usuario). |
| `responsavel_interno_nome` | texto | Nome do anfitrião. |
| `decidido_por_id` | uuid | Usuário que aprovou/recusou. |
| `decidido_por_nome` | texto | Nome de quem decidiu. |
| `decidido_em` | data/hora | Data/hora da decisão (UTC-4). |
| `minutos_ate_decisao` | inteiro | Tempo de atendimento: criação até decisão, em minutos. |
| `consentimento_lgpd` | inteiro | 1 = aceitou o aviso de privacidade. |
| `qtd_entradas` | inteiro | Entradas registradas na portaria para esta solicitação. |

#### `bi_fato_verificacao_ssma`

Verificações de SSMA (histórico imutável). **Grão:** uma linha por verificação.

| Coluna | Tipo | Descrição |
|---|---|---|
| `verificacao_id` | uuid | Chave da verificação. |
| `solicitacao_id` | uuid | Solicitação verificada. |
| `pessoa_id` | uuid | Pessoa verificada. |
| `pessoa_nome` | texto | Nome. |
| `status` | texto | apto ou nao_apto. |
| `validade_ate` | data | Validade da liberação. |
| `verificador_id` | uuid | Usuário de SSMA. |
| `verificador_nome` | texto | Nome do verificador. |
| `verificado_em` | data/hora | Data/hora (UTC-4). |
| `data_verificacao` | data | Data. |
| `itens_checklist` | inteiro | Itens no checklist. |
| `itens_conformes` | inteiro | Itens marcados como conformes. |

#### `bi_fato_documento`

Documentos de SSMA (ASO, NRs, integração, CNH) com situação calculada para hoje. **Grão:** uma linha por documento.

| Coluna | Tipo | Descrição |
|---|---|---|
| `documento_id` | uuid | Chave do documento. |
| `pessoa_id` | uuid | Pessoa. |
| `pessoa_nome` | texto | Nome. |
| `pessoa_categoria` | texto | Categoria. |
| `pessoa_empresa` | texto | Empresa. |
| `pessoa_ativa` | inteiro | 1 = pessoa ativa. |
| `tipo` | texto | aso, nr22, nr35, nr33, integracao, cnh ou outro. |
| `emissao` | data | Data de emissão. |
| `validade` | data | Data de validade. |
| `dias_para_vencer` | inteiro | Dias até o vencimento (negativo = vencido). |
| `situacao` | texto | vencido, vence_em_30_dias ou valido. |
| `mais_recente_do_tipo` | inteiro | 1 = documento mais recente da pessoa para o tipo. Filtre por 1 para contar situação atual (igual ao painel). |

### Agregados e apoio

#### `bi_agg_diario_local`

Resumo diário por local, pronto para gráficos. **Grão:** uma linha por dia e local com movimento.

| Coluna | Tipo | Descrição |
|---|---|---|
| `data` | data | Data. |
| `local_id` | uuid | Local. |
| `local_nome` | texto | Nome do local. |
| `entradas` | decimal | Total de entradas. |
| `saidas` | decimal | Total de saídas. |
| `entradas_colaboradores` | decimal | Entradas de colaboradores. |
| `entradas_visitantes` | decimal | Entradas de visitantes. |
| `entradas_terceiros` | decimal | Entradas de terceiros. |
| `pessoas_distintas` | inteiro | Pessoas diferentes que entraram. |
| `veiculos_distintos` | inteiro | Veículos diferentes que entraram. |

#### `bi_dicionario`

Este dicionário de dados da camada de BI. **Grão:** uma linha por objeto (coluna = "*") e por coluna.

| Coluna | Tipo | Descrição |
|---|---|---|
| `objeto` | texto | Nome da view/tabela. |
| `coluna` | texto | Nome da coluna ("*" = descrição do objeto). |
| `ordem` | inteiro | Posição da coluna. |
| `tipo` | texto | Tipo de dado. |
| `descricao` | texto | Descrição em linguagem de negócio. |
| `granularidade` | texto | O que representa cada linha (só na linha "*"). |

