# =====================================================================
# Salinas Access Gate - envia esta pasta para o servidor Linux e instala em Docker
# Uso: bin\INSTALAR-NO-SERVIDOR.bat   (ou: powershell -File bin\instalar-servidor.ps1 -Servidor 192.168.4.5 -Usuario admin -Porta 222)
# A senha do servidor é digitada por você nas janelas do SSH (nunca fica gravada em arquivo).
# =====================================================================
param(
    [string]$Servidor = '192.168.4.5',
    [string]$Usuario  = 'admin',
    [int]$Porta       = 222
)
$ErrorActionPreference = 'Stop'
function Etapa($t) { Write-Host ''; Write-Host "==> $t" -ForegroundColor Green }
function Falha($t) { Write-Host ''; Write-Host "ERRO: $t" -ForegroundColor Red; Read-Host 'Pressione Enter para sair'; exit 1 }

$raiz = Split-Path -Parent $PSScriptRoot
Set-Location $raiz

Etapa 'Verificando ferramentas do Windows (OpenSSH e tar)'
foreach ($c in 'ssh', 'scp', 'tar') {
    if (-not (Get-Command $c -ErrorAction SilentlyContinue)) {
        Falha "Comando '$c' não encontrado. Ative o 'Cliente OpenSSH' em Configurações > Sistema > Recursos opcionais."
    }
}

Write-Host ''
Write-Host "Servidor: $Usuario@$Servidor   Porta do sistema: $Porta" -ForegroundColor Cyan
Write-Host 'Você vai digitar a SENHA DO SERVIDOR 2 vezes (envio e instalação) e depois a senha do sudo.'
Write-Host 'Na primeira conexão o SSH pergunta se confia no servidor: confira e digite yes.'
$resp = Read-Host 'Continuar? (S/N)'
if ($resp -notmatch '^[sS]') { exit 0 }

Etapa 'Empacotando o sistema (sem .env, storage e segredos)'
$pacote = Join-Path $env:TEMP 'sag-deploy.tgz'
if (Test-Path $pacote) { Remove-Item $pacote -Force }
& tar.exe -czf $pacote --exclude=.git --exclude=.env --exclude=storage --exclude=app.env --exclude=db.env --exclude='*.zip' -C $raiz .
if ($LASTEXITCODE -ne 0) { Falha 'Falha ao gerar o pacote.' }
'{0:N1} MB' -f ((Get-Item $pacote).Length / 1MB) | Write-Host

Etapa "Enviando para $Servidor (digite a senha do servidor)"
& scp $pacote "${Usuario}@${Servidor}:/tmp/sag-deploy.tgz"
if ($LASTEXITCODE -ne 0) { Falha 'Envio falhou (senha, rede ou SSH desligado no servidor).' }

Etapa 'Instalando no servidor (digite a senha do servidor e depois a do sudo)'
$remoto = "rm -rf /tmp/sag-deploy && mkdir -p /tmp/sag-deploy && tar -xzf /tmp/sag-deploy.tgz -C /tmp/sag-deploy && sed -i 's/\r`$//' /tmp/sag-deploy/docker/*.sh && sudo SAG_PORTA=$Porta bash /tmp/sag-deploy/docker/instalar.sh /tmp/sag-deploy; rc=`$?; rm -rf /tmp/sag-deploy /tmp/sag-deploy.tgz; exit `$rc"
& ssh -t "${Usuario}@${Servidor}" $remoto
if ($LASTEXITCODE -ne 0) { Falha 'A instalação no servidor retornou erro (veja as mensagens acima).' }
Remove-Item $pacote -Force -ErrorAction SilentlyContinue

Etapa 'Pronto'
$url = "http://${Servidor}:${Porta}"
Write-Host "Salinas Access Gate: $url" -ForegroundColor Cyan
Start-Process $url
Read-Host 'Pressione Enter para fechar'
