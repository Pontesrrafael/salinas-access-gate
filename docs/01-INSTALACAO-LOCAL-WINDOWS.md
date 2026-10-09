# Instalação local no Windows

**Documento:** MD-TI-SIS-CBA-001 · Rev. 00 · Salinas Access Gate
**Objetivo:** rodar o sistema na estação do Analista de TI para validação antes do deploy.
**Tempo estimado:** 30 minutos.

---

## Atalho: instalação em um clique (XAMPP)

1. Instale o XAMPP 8.2 em `C:\xampp` e dê **Start** no **MySQL** pelo XAMPP Control Panel.
2. Dê dois cliques em `bin\INSTALAR-E-INICIAR.bat`. O script habilita as extensões, cria banco e usuário, gera o `.env` e as chaves do CPF **no seu computador**, aplica as migrações, cria o admin `ti@salinasgold.com.br` (anote a senha temporária) e abre `http://localhost:8080`.
3. Nas próximas vezes, use `bin\INICIAR.bat`.

Os itens 1 a 5 abaixo descrevem o mesmo processo de forma manual.

## 1. Pré-requisitos

| Componente | Versão | Observação |
|---|---|---|
| PHP | 8.1 ou superior (8.2 recomendado) | precisa das extensões `pdo_mysql`, `sodium`, `mbstring`, `fileinfo` e `curl` |
| Banco | MariaDB 10.6+ ou MySQL 8.0.16+ | |
| Navegador | Chrome ou Edge atualizado | |

A forma mais simples de obter PHP + MariaDB no Windows é o **XAMPP 8.2** (gratuito). Laragon ou PHP e MariaDB instalados separadamente também funcionam, porque o sistema não depende do Apache do pacote.

> No seu notebook o comando `npx` não está no PATH. Isso não afeta nada: a nova versão **não usa Node.js**.

## 2. Instalar o XAMPP

1. Baixe o XAMPP com PHP 8.2 em apachefriends.org e instale em `C:\xampp`.
2. Abra o **XAMPP Control Panel** e clique em **Start** na linha **MySQL** (é o MariaDB). O Apache não é necessário.
3. Habilite a extensão `sodium`: abra `C:\xampp\php\php.ini`, procure a linha `;extension=sodium` e remova o `;`. Salve o arquivo.
4. Coloque o PHP no PATH: em *Configurações → Sistema → Sobre → Configurações avançadas → Variáveis de ambiente → Path → Novo*, adicione `C:\xampp\php`. Depois feche e reabra o terminal.
5. Teste no PowerShell:
   ```powershell
   php -v
   php -m | findstr /i "sodium pdo_mysql fileinfo mbstring"
   ```

## 3. Criar o banco e o usuário da aplicação

No PowerShell:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root
```

Dentro do console do MariaDB:

```sql
CREATE DATABASE salinas_access_gate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sag_app'@'localhost' IDENTIFIED BY 'TroqueEstaSenha!2026';
GRANT ALL PRIVILEGES ON salinas_access_gate.* TO 'sag_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Configurar o projeto

O projeto está em `C:\Users\<usuario>\Documents\salinas-access-gate`.

```powershell
cd $HOME\Documents\salinas-access-gate
copy .env.example .env
notepad .env
```

No `.env`, ajuste estas linhas:

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=salinas_access_gate
DB_USERNAME=sag_app
DB_PASSWORD=TroqueEstaSenha!2026
```

Depois rode a sequência abaixo:

```powershell
php bin\console.php gerar-chaves --gravar
php bin\console.php migrar
php bin\console.php criar-admin --email=ti@salinasgold.com.br --nome="TI Salinas"
php bin\console.php verificar
```

- `gerar-chaves` cria as chaves de criptografia do CPF. Guarde uma cópia em cofre de senhas: **sem elas os CPFs ficam irrecuperáveis**.
- `migrar` cria 13 tabelas, 10 triggers e 3 views.
- `criar-admin` pede a senha. Se você deixar em branco, o sistema gera uma senha temporária.
- `verificar` deve mostrar todos os itens com `[OK]`.

## 5. Executar

Dê dois cliques em `bin\INICIAR.bat`, ou rode:

```powershell
php -d post_max_size=72M -d upload_max_filesize=8M -S localhost:8080 -t public public/index.php
```

Acesse **http://localhost:8080** e entre com o admin criado.

Para testar pelo celular ou tablet na mesma rede (câmera da portaria), use:

```powershell
php -d post_max_size=72M -d upload_max_filesize=8M -S 0.0.0.0:8080 -t public public/index.php
```

Depois acesse `http://<IP-do-notebook>:8080`. Atenção: em HTTP o navegador do celular pode abrir só a galeria, sem a câmera direta. A câmera direta funciona em HTTPS, ou seja, em produção.

## 6. Roteiro de validação (homologação)

| # | Teste | Resultado esperado |
|---|---|---|
| 1 | Criar usuários Porteiro, SSMA e Supervisor, este último responsável por uma área | Senha temporária exibida uma vez; troca obrigatória no primeiro acesso |
| 2 | Cadastrar um colaborador com CPF e registrar a entrada | Aparece em "Quem está no site" |
| 3 | Registrar saída duas vezes | A segunda tentativa é bloqueada ("não está presente") |
| 4 | Registrar entrada de visitante sem solicitação | Bloqueado |
| 5 | Criar a solicitação, aprovar com o responsável da área e registrar a entrada | Liberado; a solicitação fica vinculada à pessoa |
| 6 | Criar uma solicitação de risco | Vai para a fila do SSMA; só é aprovada depois de "Apto" |
| 7 | Cadastrar um terceiro com ASO vencido | Entrada bloqueada |
| 8 | Gerar um link externo e preencher numa aba anônima | A solicitação aparece como pendente |
| 9 | Exportar o relatório CSV | O arquivo abre no Excel com acentos corretos |
| 10 | Conferir a Auditoria | Login, criação, aprovação e movimentações registrados |

## 7. Tarefa agendada (opcional no local)

A rotina `php bin\console.php expirar-solicitacoes` marca como expiradas as solicitações cuja data já passou. Para agendá-la no Windows, use o Agendador de Tarefas com execução diária às 00:05 (Horário de Mato Grosso – UTC-4):

- **Programa:** `C:\xampp\php\php.exe`
- **Argumentos:** `bin\console.php expirar-solicitacoes`
- **Iniciar em:** `C:\Users\<usuario>\Documents\salinas-access-gate`

## 8. Problemas comuns

| Sintoma | Causa provável e solução |
|---|---|
| `Chave CPF_ENCRYPTION_KEY ausente/inválida` | Rode `php bin\console.php gerar-chaves --gravar` |
| `could not find driver` | Habilite `extension=pdo_mysql` no `php.ini` |
| `Call to undefined function sodium_...` | Habilite `extension=sodium` no `php.ini` |
| `SQLSTATE[HY000] [2002]` | O MySQL do XAMPP não está iniciado, ou `DB_PORT` está errado |
| Erro ao criar trigger (MySQL 8 com binlog) | Rode `migrar` com usuário root, ou execute `SET GLOBAL log_bin_trust_function_creators = 1;` |
| Página "O formulário expirou (proteção CSRF)" | A sessão expirou ou a página ficou aberta muito tempo. Recarregue e envie de novo |
