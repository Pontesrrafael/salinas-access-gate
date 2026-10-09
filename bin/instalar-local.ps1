# =====================================================================
# Salinas Access Gate - instalação/execução local no Windows com XAMPP
# Uso: dois cliques em bin\INSTALAR-E-INICIAR.bat
#  1. Habilita a extensão sodium no php.ini do XAMPP
#  2. Cria banco e usuário no MariaDB do XAMPP
#  3. Cria o .env, gera as chaves do CPF (no SEU computador), aplica as migrações
#  4. Cria o admin (1ª vez), sobe o servidor e abre o navegador
# Pode ser executado várias vezes (etapas já feitas são puladas).
# =====================================================================
$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$raiz  = Split-Path -Parent $PSScriptRoot
Set-Location $raiz
$xampp = 'C:\xampp'
$php   = Join-Path $xampp 'php\php.exe'
$mysql = Join-Path $xampp 'mysql\bin\mysql.exe'
$ini   = Join-Path $xampp 'php\php.ini'
$utf8  = New-Object System.Text.UTF8Encoding $false

function Passo($t) { Write-Host ''; Write-Host "==> $t" -ForegroundColor Green }
function Falha($t) { Write-Host ''; Write-Host "ERRO: $t" -ForegroundColor Red; Read-Host 'Pressione Enter para sair'; exit 1 }

Passo 'Verificando XAMPP'
if (-not (Test-Path $php))   { Falha "PHP não encontrado em $php. Instale o XAMPP 8.2 em C:\xampp." }
if (-not (Test-Path $mysql)) { Falha "MariaDB não encontrado em $mysql." }
& $php -v | Select-Object -First 1

Passo 'Habilitando extensões no php.ini (sodium, pdo_mysql, fileinfo, mbstring, curl, ldap)'
$conteudo = [System.IO.File]::ReadAllText($ini)
$original = $conteudo
foreach ($ext in 'sodium', 'pdo_mysql', 'fileinfo', 'mbstring', 'curl', 'ldap') {
    $conteudo = [regex]::Replace($conteudo, "(?m)^;\s*extension\s*=\s*$ext\s*$", "extension=$ext")
}
if ($conteudo -ne $original) {
    Copy-Item $ini "$ini.bak-sag" -Force
    [System.IO.File]::WriteAllText($ini, $conteudo, $utf8)
    Write-Host 'php.ini atualizado (backup em php.ini.bak-sag).'
} else { Write-Host 'Extensões já habilitadas.' }

Passo 'Verificando o MariaDB (XAMPP Control Panel > MySQL > Start)'
$rootArgs = @('-u', 'root', '--protocol=TCP', '-h', '127.0.0.1', '-P', '3306')
& $mysql @rootArgs -e 'SELECT 1' *> $null
if ($LASTEXITCODE -ne 0) {
    $senhaRoot = Read-Host 'Não conectei como root sem senha. Digite a senha do root do MariaDB (ou Enter se o MySQL estiver parado)'
    if ($senhaRoot -eq '') { Falha 'Inicie o MySQL no XAMPP Control Panel e execute novamente.' }
    $rootArgs += "-p$senhaRoot"
    & $mysql @rootArgs -e 'SELECT 1' *> $null
    if ($LASTEXITCODE -ne 0) { Falha 'Não foi possível conectar ao MariaDB com o usuário root.' }
}
Write-Host 'MariaDB ok.'

Passo 'Configurando o .env'
$envPath = Join-Path $raiz '.env'
if (-not (Test-Path $envPath)) { Copy-Item (Join-Path $raiz '.env.example') $envPath }
$envTxt = [System.IO.File]::ReadAllText($envPath)
$senhaApp = ''
if ($envTxt -match '(?m)^DB_PASSWORD=(.+)$' -and $Matches[1].Trim() -ne 'troque_esta_senha') { $senhaApp = $Matches[1].Trim() }
if ($senhaApp -eq '') { $senhaApp = 'Sag' + [guid]::NewGuid().ToString('N').Substring(0, 20) }
$valores = @{
    'APP_ENV' = 'local'; 'APP_DEBUG' = 'true'; 'APP_URL' = 'http://localhost:8080';
    'DB_HOST' = '127.0.0.1'; 'DB_PORT' = '3306'; 'DB_DATABASE' = 'salinas_access_gate';
    'DB_USERNAME' = 'sag_app'; 'DB_PASSWORD' = $senhaApp
}
foreach ($k in $valores.Keys) { $envTxt = [regex]::Replace($envTxt, "(?m)^$k=.*$", "$k=$($valores[$k])") }
[System.IO.File]::WriteAllText($envPath, $envTxt, $utf8)
Write-Host '.env pronto.'

Passo 'Criando banco salinas_access_gate e usuário sag_app'
$sql = "CREATE DATABASE IF NOT EXISTS salinas_access_gate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
       "CREATE USER IF NOT EXISTS 'sag_app'@'localhost' IDENTIFIED BY '$senhaApp'; " +
       "CREATE USER IF NOT EXISTS 'sag_app'@'127.0.0.1' IDENTIFIED BY '$senhaApp'; " +
       "ALTER USER 'sag_app'@'localhost' IDENTIFIED BY '$senhaApp'; ALTER USER 'sag_app'@'127.0.0.1' IDENTIFIED BY '$senhaApp'; " +
       "GRANT ALL PRIVILEGES ON salinas_access_gate.* TO 'sag_app'@'localhost'; GRANT ALL PRIVILEGES ON salinas_access_gate.* TO 'sag_app'@'127.0.0.1'; FLUSH PRIVILEGES;"
& $mysql @rootArgs -e $sql
if ($LASTEXITCODE -ne 0) { Falha 'Falha ao criar banco/usuário.' }
Write-Host 'Banco ok.'

Passo 'Chaves de criptografia do CPF'
$envTxt = [System.IO.File]::ReadAllText($envPath)
if ($envTxt -match '(?m)^CPF_ENCRYPTION_KEY=\S+') { Write-Host 'Chaves já existem (mantidas).' }
else {
    & $php bin\console.php gerar-chaves --gravar
    Write-Host 'IMPORTANTE: guarde CPF_ENCRYPTION_KEY e CPF_INDEX_KEY do .env em cofre de senhas.' -ForegroundColor Yellow
}

Passo 'Aplicando migrações (tabelas, triggers e views)'
& $php bin\console.php migrar
if ($LASTEXITCODE -ne 0) { Falha 'Falha nas migrações (veja a mensagem acima).' }

Passo 'Administrador'
$temAdmin = & $mysql @rootArgs -N -e "SELECT COUNT(*) FROM salinas_access_gate.usuarios WHERE papel='admin'"
if ([int]$temAdmin -eq 0) {
    Write-Host 'Criando admin ti@salinasgold.com.br. Pressione Enter na senha para gerar uma temporária.' -ForegroundColor Yellow
    & $php bin\console.php criar-admin --email=ti@salinasgold.com.br --nome="TI Salinas"
    Write-Host 'ANOTE a senha acima antes de continuar.' -ForegroundColor Yellow
    Read-Host 'Pressione Enter para iniciar o sistema'
} else { Write-Host 'Admin já existe. Esqueceu a senha? php bin\console.php redefinir-senha --email=ti@salinasgold.com.br' }

Passo 'Verificação final'
& $php bin\console.php verificar

Passo 'Iniciando o servidor em http://localhost:8080 (feche a janela do servidor para parar)'
$argsServidor = @('-d', 'upload_max_filesize=8M', '-d', 'post_max_size=72M', '-d', 'max_file_uploads=20',
          '-S', 'localhost:8080', '-t', 'public', 'public/index.php')
Start-Process -FilePath $php -ArgumentList $argsServidor -WorkingDirectory $raiz
Start-Sleep -Seconds 2
Start-Process 'http://localhost:8080'
Write-Host 'Sistema aberto no navegador. Para iniciar nas próximas vezes use bin\INICIAR.bat' -ForegroundColor Green
Start-Sleep -Seconds 5
