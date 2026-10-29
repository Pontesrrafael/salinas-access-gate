import { chromium } from 'playwright-core';
import { readdir, mkdir, readFile, writeFile, stat, rm } from 'node:fs/promises';
import path from 'node:path';
import { root, base, contexto, abrir } from './capturar.mjs';

const scripts=path.join(root,'tools/doc/roteiros');
const output=path.join(root,'docs/manual/videos');
const work=path.join(root,'tools/doc/.gravacoes');
const files=(await readdir(scripts)).filter(f=>f.endsWith('.json')).sort();
if (files.length!==8) throw new Error('São necessários os oito roteiros combinados com o manual.');
const allowed=new Set(['ir','clicar','digitar','selecionar','pausar','legenda']);
const roteiros=await Promise.all(files.map(async f=>{
  const r=JSON.parse(await readFile(path.join(scripts,f),'utf8'));
  if (!/^v0[1-8]-[a-z-]+$/.test(r.nome) || !Array.isArray(r.passos)) throw new Error(`Roteiro inválido: ${f}`);
  for (const step of r.passos) {
    if (!allowed.has(step.acao)) throw new Error(`${f}: ação desconhecida ${step.acao}`);
    if (step.acao==='ir' && (!step.rota?.startsWith('/') || step.rota.startsWith('//'))) throw new Error(`${f}: rota local obrigatória`);
    if (step.acao==='pausar' && (!Number.isFinite(step.ms) || step.ms<0 || step.ms>10000)) throw new Error(`${f}: pausa de 0–10000 ms`);
  }
  return r;
}));
if (process.argv.includes('--validar')) {
  console.log(`OK: ${roteiros.length} roteiros, ${roteiros.reduce((n,r)=>n+r.passos.length,0)} passos válidos.`);
} else {
  await mkdir(output,{recursive:true}); await mkdir(work,{recursive:true});
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const manifest=[], errors=[];
  try {
    for (const roteiro of roteiros) {
      const dir=path.join(work,roteiro.nome); await mkdir(dir,{recursive:true});
      const context=await contexto(browser,{bypassCSP:true, recordVideo:{dir,size:{width:1440,height:900}}});
      const page=await context.newPage();
      const jsErrors=[];
      page.on('pageerror',e=>jsErrors.push(e.message));
      page.on('response',r=>{if(r.status()>=400) jsErrors.push(`HTTP ${r.status()}: ${r.url()}`);});
      let texto='', inicio;
      const legenda=async()=>{
        await page.addStyleTag({content:`#demo-legenda{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:2147483647;max-width:1100px;width:max-content;padding:16px 24px;border-radius:12px;background:var(--lateral);color:var(--ouro-claro);border:2px solid var(--ouro);font:600 22px/1.45 var(--fonte);text-align:center;box-shadow:var(--sombra);pointer-events:none}`});
        await page.evaluate(text=>{document.querySelector('#demo-legenda')?.remove();const el=document.createElement('div');el.id='demo-legenda';el.textContent=text;document.body.append(el);},texto);
      };
      try {
        for (const step of roteiro.passos) {
          if (step.acao==='legenda') { texto=step.texto; if(page.url()!=='about:blank') await legenda(); }
          else if (step.acao==='ir') {
            await abrir(page,step.rota); inicio??=Date.now(); await legenda();
          } else if (step.acao==='pausar') await page.waitForTimeout(step.ms);
          else {
            const target=page.locator(step.alvo);
            await target.scrollIntoViewIfNeeded();
            const act=async()=>{
              if(step.acao==='clicar') await target.click();
              if(step.acao==='digitar') {await target.fill('');await target.pressSequentially(step.texto,{delay:step.atrasoMs??45});}
              if(step.acao==='selecionar') await target.selectOption(step.valor);
            };
            if(step.espera) await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}),act()]);
            else await act();
            if(step.espera) await page.waitForURL(new RegExp(step.espera));
            if(step.ver) await page.getByText(step.ver,{exact:false}).first().waitFor({state:'visible'});
            await page.waitForLoadState('networkidle');
            await legenda();
          }
        }
        const remaining=24000-(Date.now()-inicio);
        if(remaining>0) await page.waitForTimeout(remaining);
        if(Date.now()-inicio>57000) throw new Error('Roteiro excedeu 57 s; reduza pausas ou texto.');
        if(jsErrors.length) throw new Error(jsErrors.join('; '));
        const video=page.video(); await context.close();
        const dest=path.join(output,`${roteiro.nome}.webm`); await video.saveAs(dest); await video.delete();
        const buffer=await readFile(dest);
        const info=webmInfo(buffer);
        if(info.segundos<20 || info.segundos>60 || info.largura!==1440 || info.altura!==900) throw new Error(`Formato/duração fora do combinado: ${JSON.stringify(info)}`);
        manifest.push({arquivo:path.basename(dest),bytes:(await stat(dest)).size,...info,roteiro:`tools/doc/roteiros/${roteiro.nome}.json`});
        console.log(`OK ${roteiro.nome}: ${info.segundos}s, ${buffer.length} bytes`);
        if (!path.resolve(dir).startsWith(path.resolve(work)+path.sep)) throw new Error('Diretório temporário fora de .gravacoes.');
        await rm(dir,{recursive:true}); // Somente o diretório conhecido em .gravacoes.
      } catch(error) {errors.push(`${roteiro.nome}: ${error.message}`);await context.close();console.error(errors.at(-1));}
    }
    await writeFile(path.join(output,'manifesto.json'),JSON.stringify({geradoEm:new Date().toISOString(),navegador:browser.version(),videos:manifest,erros:errors},null,2)+'\n');
    if(errors.length) throw new Error(errors.join('\n'));
  } finally {await browser.close();}
}

// Lê os metadados EBML/WebM sem instalar ffprobe nem baixar ferramentas.
function webmInfo(buf) {
  const vint=(pos,keep=false)=>{const first=buf[pos];if(!first) throw new Error('EBML inválido');let n=1;while(n<=8 && !(first & (1<<(8-n)))) n++;let v=keep?first:first&((1<<(8-n))-1);for(let i=1;i<n;i++) v=v*256+buf[pos+i];return {n,v};};
  const children=(start,end)=>{const out=[];for(let p=start;p<end;){const id=vint(p,true);p+=id.n;const size=vint(p);p+=size.n;const stop=Math.min(end,p+size.v);out.push({id:id.v,start:p,end:stop});p=stop;}return out;};
  const segment=children(0,buf.length).find(e=>e.id===0x18538067);
  if(!segment) throw new Error('WebM sem segmento');
  const elems=children(segment.start,segment.end), info=elems.find(e=>e.id===0x1549a966), tracks=elems.find(e=>e.id===0x1654ae6b);
  const integer=e=>{let v=0;for(let p=e.start;p<e.end;p++)v=v*256+buf[p];return v;};
  let scale=1000000,duration=0,width=0,height=0;
  for(const e of children(info.start,info.end)) {if(e.id===0x2ad7b1)scale=integer(e);if(e.id===0x4489) duration=e.end-e.start===8?buf.readDoubleBE(e.start):buf.readFloatBE(e.start);}
  for(const track of children(tracks.start,tracks.end)) for(const e of children(track.start,track.end)) if(e.id===0xe0) for(const v of children(e.start,e.end)) {if(v.id===0xb0)width=integer(v);if(v.id===0xba)height=integer(v);}
  return {segundos:Math.round(duration*scale/1e9*100)/100,largura:width,altura:height};
}
