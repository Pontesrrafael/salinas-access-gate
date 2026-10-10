@echo off
rem Salinas Access Gate - liga esta pasta ao branch php-mariadb do GitHub.
rem Preserva .env, storage/ e demais arquivos locais (ignorados pelo .gitignore).
chcp 65001 >nul
setlocal
cd /d "%~dp0.."
set REPO=https://github.com/Pontesrrafael/physical-entry-flow.git
set BRANCH=php-mariadb

where git >nul 2>nul
if errorlevel 1 (
  echo [ERRO] Git nao encontrado no PATH.
  echo        Instale com:  winget install --id Git.Git -e
  echo        Depois feche e abra esta janela novamente.
  pause & exit /b 1
)

if exist ".git" (
  echo Esta pasta ja esta ligada ao Git. Atualizando...
  git pull --ff-only
  git status -sb
  pause & exit /b 0
)

git init -q
git checkout -q -b %BRANCH%
git config core.autocrlf true
git remote add origin %REPO%
echo Baixando %BRANCH% do GitHub (pode abrir o login do GitHub no navegador)...
git fetch origin %BRANCH%
if errorlevel 1 (
  echo [ERRO] Nao foi possivel baixar. Confira o login no GitHub e rode novamente.
  rmdir /s /q .git
  pause & exit /b 1
)
git reset -q --mixed origin/%BRANCH%
git branch -q --set-upstream-to=origin/%BRANCH% %BRANCH%
echo.
git status -sb
echo.
echo Pronto. No GitHub Desktop use: File ^> Add local repository ^> %CD%
start "" https://github.com/Pontesrrafael/physical-entry-flow/tree/%BRANCH%
pause
