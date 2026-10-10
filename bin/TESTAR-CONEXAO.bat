@echo off
REM Diagnostico de rede ate o servidor (nao usa senha). Resultado: diagnostico-rede.txt na pasta do projeto
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0testar-conexao.ps1" %*
