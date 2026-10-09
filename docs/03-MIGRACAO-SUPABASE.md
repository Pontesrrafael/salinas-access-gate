# Migração dos dados — Lovable/Supabase → MariaDB

**Documento:** MD-TI-SIS-CBA-003 · Rev. 00 · Salinas Access Gate

## 1. O que é migrado

| Tabela Supabase | Destino | Observação |
|---|---|---|
| `locais`, `areas`, `veiculos`, `documentos` | mesmas tabelas | IDs (UUID) preservados |
| `pessoas` | `pessoas` | CPF **cifrado** na importação. CPF inválido ou duplicado vai para o campo RG/documento, para revisão |
| `profiles` + `user_roles` | `usuarios` | **As senhas não são exportáveis** do Supabase Auth: cada usuário recebe uma senha temporária com troca obrigatória |
| `solicitacoes_acesso`, `verificacoes_ssma` | mesmas tabelas | Tokens de link externo preservados |
| `registros_acesso` | `registros_acesso` | Ordenados por data. A trigger de auditoria é desligada durante a importação para não duplicar eventos |
| `registro_anexos` + bucket `registros-acesso-fotos` | `registro_anexos` + `storage/uploads` | Só entram se os arquivos do bucket forem baixados |
| `audit_log` | `audit_log` | Histórico mantido, com IP marcado como `supabase` |

As datas `timestamptz` (UTC) são convertidas para o **Horário de Mato Grosso – UTC-4**.

## 2. Exportar do Lovable Cloud

1. No Lovable, abra o projeto **Salinas Access Gate → Cloud → Database → Tables**.
2. Exporte **cada tabela** em CSV, com o nome exato da tabela: `pessoas.csv`, `profiles.csv`, `user_roles.csv`, `locais.csv`, `areas.csv`, `veiculos.csv`, `documentos.csv`, `solicitacoes_acesso.csv`, `verificacoes_ssma.csv`, `registros_acesso.csv`, `registro_anexos.csv` e `audit_log.csv`.
   Se preferir, peça no chat do Lovable: *"Exporte todas as tabelas para CSV com o nome real de cada tabela e cabeçalho"*.
3. Fotos (opcional): em *Cloud → Storage → registros-acesso-fotos*, baixe as pastas e mantenha a estrutura `<registro_id>/<arquivo>` dentro de uma pasta chamada `registros-acesso-fotos`.
4. Guarde tudo em uma pasta, por exemplo `C:\migracao-sag\`:
   ```
   migracao-sag\
     pessoas.csv  profiles.csv  user_roles.csv  ...  audit_log.csv
     registros-acesso-fotos\<registro_id>\<arquivo>.jpg
   ```

> Os CSVs contêm CPF e dados pessoais (LGPD). Mantenha a pasta apenas na estação da TI e **apague** os arquivos depois da migração.

## 3. Importar

```powershell
# 1) Simulação: valida tudo e desfaz no final
php bin\console.php importar-supabase C:\migracao-sag --simular

# 2) Importação real
php bin\console.php importar-supabase C:\migracao-sag
```

O resultado mostra a contagem por tabela. Reexecutar é seguro: registros com o mesmo ID são ignorados.

As senhas temporárias ficam em `storage\importacao\senhas-temporarias-AAAAMMDD-HHMMSS.csv`.
Distribua por canal seguro e **apague o arquivo** em seguida.

## 4. Conferência (amostragem)

| Verificação | Como |
|---|---|
| Contagem por tabela | Compare com o total de linhas de cada CSV |
| Presença atual | "Quem está no site" deve bater com o Lovable no momento da exportação |
| CPF | Abra 3 pessoas como Admin e confira o CPF completo |
| Datas | Um registro conhecido deve mostrar o mesmo horário local visto no Lovable |
| Usuários | Faça login com a senha temporária e confirme a troca obrigatória |
| Pendências | Revise as pessoas com documento movido para o campo RG (aviso da importação) |

## 5. Virada (cutover)

1. Combine a janela com as portarias. Fora do horário de pico, a portaria registra em papel por cerca de 30 minutos.
2. Exporte os CSVs do Lovable (item 2) **no início da janela**.
3. Importe no servidor de produção. Lá devem estar as **mesmas chaves de CPF** que serão usadas daqui em diante.
4. Faça a conferência (item 4) e libere as portarias no novo endereço `https://accessgate.mycode.app.br`.
5. Lance no sistema os registros feitos em papel durante a janela.
6. Desative a publicação do app no Lovable e revogue o servidor MCP antigo (OAuth).
7. Mantenha o projeto Lovable arquivado por 90 dias, apenas para consulta, e depois exclua os dados do Lovable Cloud.
