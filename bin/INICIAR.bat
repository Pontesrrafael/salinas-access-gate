@echo off
REM Salinas Access Gate - inicia o servidor local (depois da instalacao)
cd /d "%~dp0.."
set PHP=php
if exist "C:\xampp\php\php.exe" set PHP=C:\xampp\php\php.exe
echo Aplicando atualizacoes do banco (se houver)...
"%PHP%" bin\console.php migrar
echo Salinas Access Gate em http://localhost:8080  (feche esta janela para parar)
start "" cmd /c "timeout /t 2 >nul & start http://localhost:8080"
"%PHP%" -d upload_max_filesize=25M -d post_max_size=110M -d max_input_time=600 -d max_file_uploads=20 -S localhost:8080 -t public public/index.php
