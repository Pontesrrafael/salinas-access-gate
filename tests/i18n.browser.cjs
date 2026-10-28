/* Requer o roteador isolado de tests/fixtures/tutorial-router.php e Playwright instalado. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.TUTORIAL_TEST_URL || 'http://127.0.0.1:8238';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    const errors = [], writes = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if (r.method() !== 'GET') { writes.push(r.url()); } });
    const dict = locale => locale === 'pt-BR' ? {} : JSON.parse(fs.readFileSync(path.join(__dirname, '../app/Lang', locale + '.json'), 'utf8'));
    await page.goto(base + '/login');
    assert.equal(await page.locator('html').getAttribute('lang'), 'pt-BR');
    for (const locale of ['en', 'es', 'pt-BR']) {
      const d = dict(locale), tr = source => d[source] || source;
      await page.locator('select[name="lang"]').selectOption(locale);
      await Promise.all([page.waitForURL(url => url.searchParams.get('lang') === locale), page.locator('form.seletor-idioma button').click()]);
      assert.equal(await page.locator('html').getAttribute('lang'), locale);
      assert.equal(await page.locator('button[type="submit"]').last().textContent(), tr('Entrar'));
      assert.equal(await page.locator('input[name="email"]').getAttribute('type'), 'email');
      assert.equal(await page.locator('input[name="senha"]').getAttribute('type'), 'password');
      await page.goto(base + '/pessoas?q=Entrada&role=porteiro');
      assert.equal(await page.locator('html').getAttribute('lang'), locale, 'Preferência não persistiu.');
      assert.equal(await page.locator('h1').textContent(), tr('Pessoas'));
      assert.equal(await page.locator('tbody strong').textContent(), 'Pessoas', 'Nome foi traduzido.');
      assert.equal(await page.locator('tbody td').nth(2).textContent(), 'Entrada', 'Empresa foi traduzida.');
      assert.equal(await page.locator('main input[name="q"]').inputValue(), 'Entrada');
      assert.equal(await page.locator('select[name="categoria"] option').nth(2).getAttribute('value'), 'visitante');
      await page.goto(base + '/tutoriais?role=porteiro');
      assert.equal(await page.locator('a[href$="/configuracoes"]').count(), 0);
      await page.locator('[data-tutorial-open="portaria"]').click();
      assert.equal(await page.locator('#tutorial-help-title').textContent(), tr('Portaria') + ' — ' + tr('Como usar'));
      assert.equal(await page.locator('#tutorial-help details summary').textContent(), tr('Registrar uma movimentação'));
      await page.keyboard.press('Escape');
      if (process.env.TUTORIAL_SCREENSHOTS && locale !== 'pt-BR') { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/tutoriais-' + locale + '.png', fullPage: true }); }
      await page.goto(base + '/login');
    }
    await page.goto(base + '/login?lang=invalid');
    assert.equal(await page.locator('html').getAttribute('lang'), 'pt-BR');
    await page.goto(base + '/login?lang=en&ldap=1');
    assert.equal(await page.locator('input[name="email"]').getAttribute('type'), 'text');
    assert.equal(await page.locator('input[name="email"]').getAttribute('placeholder'), dict('en')['Ex.: usuário de rede']);
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto(base + '/login?lang=es');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const controls = await page.locator('.seletor-tema').boundingBox();
    const loginCard = await page.locator('main').boundingBox();
    assert.ok(controls.y + controls.height <= loginCard.y, 'Controles de idioma/tema sobrepõem o login no celular.');
    if (process.env.TUTORIAL_SCREENSHOTS) { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/login-es-mobile.png' }); }
    assert.deepEqual(errors, []);
    assert.deepEqual(writes, [], 'Alterar idioma fez POST de negócio.');
    console.log('Idiomas UI: PT/EN/ES, login, cookie, menu, tutoriais, campos, dados originais e celular OK.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
