@echo off
REM Salinas Access Gate - instala/atualiza no servidor Linux (Docker). Padrao: admin@192.168.4.5, porta 222
REM Outro servidor: INSTALAR-NO-SERVIDOR.bat IP USUARIO PORTA_SISTEMA PORTA_SSH  (ex.: 192.168.4.5 admin 222 2222)
setlocal
set SRV=%~1
set USR=%~2
set PRT=%~3
set SSHP=%~4
if "%SRV%"=="" set SRV=192.168.4.5
if "%USR%"=="" set USR=admin
if "%PRT%"=="" set PRT=222
if "%SSHP%"=="" set SSHP=22
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar-servidor.ps1" -Servidor %SRV% -Usuario %USR% -Porta %PRT% -PortaSsh %SSHP%
