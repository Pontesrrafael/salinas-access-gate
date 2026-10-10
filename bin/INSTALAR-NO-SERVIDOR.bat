@echo off
REM Salinas Access Gate - instala/atualiza no servidor Linux (Docker). Padrao: admin@192.168.4.5, porta 222
REM Outro servidor: INSTALAR-NO-SERVIDOR.bat 192.168.4.10 usuario 222
setlocal
set SRV=%~1
set USR=%~2
set PRT=%~3
if "%SRV%"=="" set SRV=192.168.4.5
if "%USR%"=="" set USR=admin
if "%PRT%"=="" set PRT=222
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar-servidor.ps1" -Servidor %SRV% -Usuario %USR% -Porta %PRT%
