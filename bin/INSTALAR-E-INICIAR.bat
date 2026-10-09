@echo off
REM Salinas Access Gate - instala (1a vez) e inicia o sistema local com XAMPP
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0instalar-local.ps1"
if errorlevel 1 pause
