/* Exercita views reais com dados fictícios e sem submissões de negócio. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.TUTORIAL_TEST_URL || 'http://127.0.0.1:8238';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage({ viewport: { width: 375, height: 667 } });
    const errors = [], writes = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if (r.method() !== 'GET') writes.push(r.url()); });
    for (const lang of ['pt-BR', 'en', 'es']) {
      const dictionary = lang === 'pt-BR' ? {} : JSON.parse(fs.readFileSync(path.join(__dirname, '../app/Lang', lang + '.json'), 'utf8'));
      const tr = s => dictionary[s] || s;
      for (const route of ['/pessoas/nova', '/pessoas/pessoa-ficticia', '/veiculos/novo', '/usuarios/novo', '/ssma/solicitacao-ficticia']) {
        await page.goto(base + route + '?role=admin&lang=' + lang);
        const config = await page.locator('#tutorial-config').textContent().then(JSON.parse);
        const module = config.catalog[config.current];
        assert.match(module.context, /-formulario$/);
        assert.equal(module.tour.some(s => s.target === '[data-tour="filtros"]'), false);
        assert.equal(await page.locator('[data-tour="formulario"]').count(), 1);
        assert.equal(module.procedures[0].title, tr('Conferir e salvar'));
        await page.locator('[data-tutorial-open]').first().click();
        await page.locator('#tutorial-help-body button').first().click();
        for (let n = 0; n < module.tour.length; n++) {
          const box = await page.locator('#tutorial-balloon').boundingBox();
          assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= 376 && box.y + box.height <= 668, route + ': tour fora da tela');
          if (process.env.TUTORIAL_SCREENSHOTS && route === '/pessoas/nova' && n === 1) {
            await page.screenshot({ path: path.join(process.env.TUTORIAL_SCREENSHOTS, 'formulario-' + lang + '.png') });
          }
          await page.locator('#tutorial-next').click();
        }
        const state = await page.evaluate(key => JSON.parse(localStorage.getItem(key)), 'sag:tutoriais:fixture-admin:' + module.context + ':v' + module.version);
        assert.equal(state.state, 'concluido');
      }
    }
    // Um modal de negócio deve conservar foco e prioridade quando surge durante o tour.
    await page.goto(base + '/painel?tutorial=painel&tour=1&role=admin');
    await page.locator('#tutorial-tour[open]').waitFor();
    await page.evaluate(() => {
      const d = document.createElement('dialog'); d.id = 'operacao-fixture';
      d.innerHTML = '<button autofocus>Operação de teste</button>'; document.body.append(d); d.showModal();
    });
    await page.locator('#tutorial-tour').waitFor({ state: 'hidden' });
    assert.equal(await page.evaluate(() => !!document.activeElement.closest('#operacao-fixture')), true);
    assert.deepEqual(errors, []); assert.deepEqual(writes, []);
    console.log('PASS: formulários reais PT/EN/ES, celular, progresso contextual e prioridade de modal operacional; nenhum POST.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
