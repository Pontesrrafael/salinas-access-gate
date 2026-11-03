import { chromium } from 'playwright-core';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

export const root = fileURLToPath(new URL('../../', import.meta.url));
export const base = process.env.DEMO_URL || 'http://127.0.0.1:8260';
const origin = new URL(base);
if (!['127.0.0.1', 'localhost', '[::1]'].includes(origin.hostname) || origin.protocol !== 'http:') {
  throw new Error('Use somente uma vitrine HTTP de loopback.');
}

export const capturas = [
  ['01-login','/login'], ['02-login-mfa','/login/mfa'], ['03-painel','/painel'],
  ['04-seletor-perfil','/painel','perfil'], ['05-seletor-idioma','/painel','idioma'],
  ['06-portaria','/portaria?perfil=porteiro'], ['07-portaria-analise','/portaria?perfil=porteiro','analise'],
  ['08-solicitacoes','/solicitacoes'], ['09-solicitacao-externa-formulario','/solicitar-acesso'],
  ['10-ssma-verificacao','/ssma/demo?perfil=ssma'], ['11-quem-esta-no-site','/presenca'],
  ['12-pessoas-lista','/pessoas'], ['13-pessoa-cadastro','/pessoas/nova'], ['14-veiculos','/veiculos'],
  ['15-locais','/locais'], ['16-indicadores','/indicadores'], ['17-relatorios','/relatorios'],
  ['18-auditoria','/auditoria'], ['19-usuarios-lista','/usuarios'], ['20-usuario-perfis','/usuarios/demo'],
  ['21-configuracoes','/configuracoes'], ['22-abrir-chamado','/chamados/abrir?perfil=colaborador'],
  ['23-meus-chamados','/chamados/meus?perfil=colaborador'], ['24-fila-atendimento','/chamados/fila?perfil=gestor_area'],
  ['25-detalhe-atendente','/chamados/demo?perfil=gestor_area'],
  ['26-detalhe-solicitante-aprovar','/chamados/demo?perfil=colaborador'],
  ['27-prorrogar-prazo','/chamados/demo?perfil=gestor_area','prorrogar'],
  ['28-areas-e-formularios','/servicos/config'], ['29-notificacoes-email','/conta/notificacoes'],
  ['30-duas-etapas','/conta/mfa'], ['31-aviso-privacidade','/privacidade'], ['32-tutoriais','/tutoriais'],
  ['cel-01-abrir-chamado','/chamados/abrir?perfil=colaborador'],
  ['cel-02-fila','/chamados/fila?perfil=gestor_area'], ['cel-03-portaria','/portaria?perfil=porteiro'],
];

export async function contexto(browser, options = {}) {
  const context = await browser.newContext({ viewport:{width:1440,height:900}, deviceScaleFactor:1,
    colorScheme:'light', locale:'pt-BR', timezoneId:'America/Cuiaba', ...options });
  await context.addCookies([{name:'sag_tema',value:'claro',url:base}]);
  // O convite de "primeira visita" dos tutoriais cobriria parte das telas; mantém oculto.
  await context.addInitScript(() => {
    const ocultar = () => { const el = document.getElementById('tutorial-invite'); if (el && !el.hidden) el.hidden = true; };
    new MutationObserver(ocultar).observe(document, { subtree:true, childList:true, attributes:true, attributeFilter:['hidden'] });
  });
  // Impede vazamento para serviços externos e qualquer operação de escrita.
  await context.route('**/*', route => {
    const req=route.request(), url=new URL(req.url());
    if (url.origin !== origin.origin || req.method() !== 'GET') return route.abort('blockedbyclient');
    return route.continue();
  });
  return context;
}

export async function abrir(page, rota) {
  const response=await page.goto(new URL(rota,base).href, {waitUntil:'networkidle'});
  if (!response?.ok()) throw new Error(`${rota}: HTTP ${response?.status()}`);
  if (response.headers()['x-sag-vitrine']!=='local-sem-banco') throw new Error('A URL não é a vitrine local.');
  await page.evaluate(() => document.fonts.ready);
  const text=await page.locator('body').innerText();
  if (/Fatal error|Warning:|Uncaught |Erro interno|Undefined (variable|array)/i.test(text)) throw new Error(`${rota}: falha PHP na tela`);
  await page.locator('img').evaluateAll(async images => Promise.all(images.map(img => img.complete ? null : new Promise(resolve => {img.onload=img.onerror=resolve;}))));
}

async function preparar(page, modo) {
  if (modo === 'perfil') await page.locator('summary').filter({hasText:'Perfil:'}).click();
  if (modo === 'idioma') await page.locator('summary[aria-label^="Alterar idioma"]').click();
  if (modo === 'analise') {
    await page.locator('#busca-pessoa').fill('Lia');
    await page.locator('#res-pessoa button').first().click();
    await page.locator('#pessoa-card').waitFor({state:'visible'});
    await page.locator('#destino').scrollIntoViewIfNeeded();
  }
  if (modo === 'prorrogar') {
    await page.locator('[name="prorrogacao_motivo"]').fill('Aguardando peça de reposição para concluir o serviço.');
    await page.locator('[name="prorrogacao_motivo"]').scrollIntoViewIfNeeded();
  }
}

async function main() {
  const dir=path.join(root,'docs/manual/imagens'); await mkdir(dir,{recursive:true});
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const results=[], errors=[];
  try {
    for (const [nome,rota,modo] of capturas) {
      const mobile=nome.startsWith('cel-');
      const context=await contexto(browser,{viewport:{width:mobile ? 390 : 1440,height:mobile ? 844 : 900},isMobile:mobile,hasTouch:mobile});
      const page=await context.newPage();
      page.on('pageerror',e=>errors.push(`${nome}: ${e.message}`));
      page.on('response',r=>{ if(r.status()>=400) errors.push(`${nome}: HTTP ${r.status()} ${r.url()}`); });
      try {
        await abrir(page,rota); await preparar(page,modo);
        const layout=await page.evaluate(()=>({largura:innerWidth,conteudo:document.documentElement.scrollWidth}));
        if (layout.conteudo>layout.largura+1) throw new Error(`Rolagem horizontal: ${JSON.stringify(layout)}`);
        const missing=await page.locator('img').evaluateAll(imgs=>imgs.filter(i=>!i.complete || !i.naturalWidth).map(i=>i.src));
        if(missing.length) throw new Error(`Imagens ausentes: ${missing.join(', ')}`);
        // Formulários longos ficam completos; detalhes de ação preservam o trecho visível.
        const buffer=await page.screenshot({path:path.join(dir,`${nome}.png`),fullPage:!['prorrogar','analise'].includes(modo),animations:'disabled'});
        results.push({arquivo:`${nome}.png`,bytes:buffer.length,...layout,rota});
        console.log(`OK ${nome} (${buffer.length} bytes)`);
      } catch(error) { errors.push(`${nome}: ${error.message}`); console.error(`FALHA ${nome}: ${error.message}`); }
      finally { await context.close(); }
    }
    await writeFile(path.join(dir,'manifesto.json'),JSON.stringify({geradoEm:new Date().toISOString(),navegador:browser.version(),capturas:results,erros:errors},null,2)+'\n');
    if(errors.length) throw new Error(errors.join('\n'));
  } finally { await browser.close(); }
}
async function verificarHttp() {
  const results=[];
  for(const [nome,rota] of capturas) {
    const r=await fetch(new URL(rota,base)), html=await r.text();
    if(!r.ok || r.headers.get('x-sag-vitrine')!=='local-sem-banco' || /Fatal error|Warning:|Uncaught |Undefined (variable|array)/i.test(html)) throw new Error(`${nome}: resposta inválida HTTP ${r.status}`);
    if(!html.includes('Vitrine local')) throw new Error(`${nome}: identificação da vitrine ausente`);
    const assets=[...html.matchAll(/(?:src|href)="(\/assets\/[^\"]+)"/g)].map(m=>m[1].replaceAll('&amp;','&'));
    for(const url of new Set(assets)) {const a=await fetch(new URL(url,base));if(!a.ok)throw new Error(`${nome}: asset HTTP ${a.status} ${url}`);await a.arrayBuffer();}
    results.push({nome,rota,status:r.status,assets:new Set(assets).size});
  }
  const blocked=await fetch(new URL('/chamados',base),{method:'POST',body:'titulo=nao-gravar'});
  if(blocked.status!==405) throw new Error('A vitrine precisa rejeitar POST com 405.');
  const dir=path.join(root,'docs/manual/imagens');await mkdir(dir,{recursive:true});
  await writeFile(path.join(dir,'verificacao-http.json'),JSON.stringify({geradoEm:new Date().toISOString(),tipo:'HTTP, sem renderização visual',telas:results,post:blocked.status},null,2)+'\n');
  console.log(`OK: ${results.length} telas e assets por HTTP; POST rejeitado com 405. Não valida renderização nem vídeos.`);
}
if (process.argv[1] && import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href) (process.argv.includes('--verificar-http')?verificarHttp():main()).catch(e=>{console.error(e);process.exitCode=1;});
