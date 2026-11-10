# Vitrine local para as imagens e os vídeos do manual

Ferramentas da branch `feat/manual-codex`. A vitrine renderiza as views reais do checkout, com dados de demonstração em `dados.php`. Não usa banco, contas reais, integrações ou rotas da aplicação. Não publique `tools/` no servidor.

Acesso somente pelo servidor PHP embutido e por loopback. Requisições que não sejam GET recebem 405. Os formulários são interceptados por um script exclusivo da vitrine: digitar e selecionar acontece nos controles reais; enviar apenas navega para outro estado fictício. O aviso no topo identifica esse comportamento em todas as telas. O QR, a chave MFA e os códigos mostrados são exemplos públicos, sem validade para contas reais.

## Executar a vitrine

Na raiz `C:/dev/salinas-access-gate-codex`, em PowerShell:

```powershell
$phpDoc = 'C:/Users/rafael.pontes/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.NTS.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe'
& $phpDoc -S 127.0.0.1:8260 -t public tools/demo/vitrine.php
```

Abra `http://127.0.0.1:8260/painel`. Use os caminhos reais de tela, por exemplo `/portaria`, `/chamados/abrir`, `/chamados/fila`, `/servicos/config`, `/conta/mfa`. Telas fora da vitrine recebem 404.

- Perfil ativo: `?perfil=admin|porteiro|ssma|supervisor|gestor_area|colaborador`.
- Idioma: `?lang=pt-BR|en|es`, ou o seletor real no topo.
- Tema: botão real no menu; os scripts começam no tema claro.
- Detalhe do chamado: `/chamados/demo?perfil=colaborador` mostra a solução para aprovação; `?perfil=gestor_area` mostra o atendimento.
- Estados demonstrativos: `estado=novo|atribuido|planejado|pendente|solucionado|fechado`. Use somente para preparar cenas, pois não há persistência.

## Gerar os arquivos

Em outro terminal, na mesma raiz:

```powershell
node tools/doc/capturar.mjs
node tools/doc/videos.mjs
```

O `playwright-core` já está em `tools/doc/node_modules`, o Chrome está instalado e o ffmpeg do Playwright já está no cache local. Não há comandos de instalação ou download. Os dois scripts usam `chromium.launch({channel:'chrome', headless:true})`, com contextos isolados, sem reutilizar o perfil pessoal do navegador.

O endereço padrão é `http://127.0.0.1:8260`. Para outra porta de loopback, defina `DEMO_URL`. Os scripts recusam endereços externos e verificam o cabeçalho `X-SAG-Vitrine: local-sem-banco`; bloqueiam também requisições externas e métodos diferentes de GET.

`capturar.mjs` gera 32 PNGs de 1440 px e 3 PNGs de celular de 390 px em `docs/manual/imagens/`, com os nomes definidos em `team/manual.md`. Páginas longas usam captura completa; a análise da portaria e a prorrogação mostram o trecho da ação. A captura verifica erros PHP, exceções JavaScript, assets ausentes e rolagem horizontal. `manifesto.json` registra dimensões de layout e tamanho de cada arquivo.

`videos.mjs` lê os oito arquivos de `tools/doc/roteiros/`. Os passos aceitos são `ir`, `clicar`, `digitar`, `selecionar`, `pausar` e `legenda`. Cada WebM usa 1440×900, com legenda em português na parte inferior, e deve durar entre 20 e 60 segundos. O roteiro mantém pelo menos 24 segundos de demonstração; o gerador confere duração e dimensões nos metadados WebM. A exceção de CSP para o estilo da legenda existe somente no contexto de gravação. `docs/manual/videos/manifesto.json` informa bytes, duração e roteiro de origem.

Os arquivos temporários de vídeo, o perfil de execução local e `node_modules` são ignorados pelo Git. Não grave vídeos com dados de produção nem use o roteiro de MFA em uma conta verdadeira.

## Verificações sem abrir navegador

```powershell
node tools/doc/capturar.mjs --verificar-http
node tools/doc/videos.mjs --validar
node --check tools/doc/capturar.mjs
node --check tools/doc/videos.mjs
```

O primeiro comando verifica as 35 telas e seus assets, confirma a rejeição de POST e grava `docs/manual/imagens/verificacao-http.json`. O segundo valida os oito roteiros. Essas verificações não comprovam a aparência das páginas, a interação no Chrome ou a geração das mídias.

## Estado desta entrega em 29/10/2026

Concluídos: sintaxe PHP/JavaScript, 35 telas e assets por HTTP, rejeição de POST com 405, oito roteiros com 121 passos e 12 transições do script de simulação verificadas por JavaScript e HTTP. Nenhum código da aplicação foi alterado.

Pendências: gerar as 35 imagens e os oito vídeos e revisar visualmente os resultados. O ambiente retornou `spawn EPERM` ao iniciar o Chrome pelo Playwright, inclusive pelo runtime Node; a tentativa de inicialização separada foi rejeitada pela política de execução. Nenhuma imagem ou gravação foi produzida, e não há tamanhos ou durações de mídias a declarar.

Pontos de melhoria: após a execução em ambiente com Chrome autorizado, revisar as legendas e o enquadramento no desktop e celular, conferir cada cena e entregar os manifestos ao gerente para integração com o manual do Gemini. Estados simulados não homologam regras, autenticação, envio de e-mail ou MFA real.
