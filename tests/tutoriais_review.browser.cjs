/* Regressões de ciclo do tour, exclusivamente na fixture local sem banco.
 * Servidor deve ser iniciado separadamente com tests/fixtures/tutorial-router.php.
 * PLAYWRIGHT_MODULE e TUTORIAL_TEST_URL permitem usar o runtime/porta existentes.
 */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.TUTORIAL_TEST_URL || 'http://127.0.0.1:8238';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  const errors = [], writes = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => { if (r.method() !== 'GET') writes.push(r.url()); });
  const open = async (route = '/painel') => {
    await page.goto(base + route + (route.includes('?') ? '&' : '?') + 'role=admin');
    await page.locator('#tutorial-config').waitFor({ state: 'attached' });
  };
  const help = async () => {
    await page.locator('[data-tutorial-open]').first().click();
    await page.locator('#tutorial-help[open]').waitFor();
  };
  const start = async () => {
    await page.locator('#tutorial-help-body button').filter({ hasText: /^(Fazer o tour|Retomar tour|Reiniciar tour)$/ }).first().click();
    await page.locator('#tutorial-tour[open]').waitFor();
  };
  const counter = () => page.locator('#tutorial-counter').textContent();
  try {
    await open();
    await page.evaluate(() => localStorage.clear());
    await open(); await help();
    assert.equal(await page.locator('#tutorial-help-body button').filter({ hasText: /^(Fazer o tour|Reiniciar tour)$/ }).count(), 1,
      'Primeira utilização deve oferecer uma única ação para iniciar');
    await start();
    assert.match(await counter(), /Passo 1 de/);
    for (let n = 0; n < 30 && await page.locator('#tutorial-tour').evaluate(el => el.open); n++) {
      await page.locator('#tutorial-next').click();
    }
    assert.equal(await page.locator('#tutorial-tour').evaluate(el => el.open), false, 'Tour deve concluir');
    await help(); await start();
    assert.match(await counter(), /Passo 1 de/, 'Tour concluído deve recomeçar pelo primeiro passo');
    await page.locator('#tutorial-next').click();
    const interrupted = await counter();
    // Recarregar representa interrupção/navegação, diferente de dispensar explicitamente.
    await open(); await help();
    await page.getByRole('button', { name: 'Retomar tour', exact: true }).click();
    await page.locator('#tutorial-tour[open]').waitFor();
    assert.equal(await counter(), interrupted, 'Tour em andamento deve retomar o passo salvo');
    await open('/painel?tutorial=painel&tour=1');
    await page.locator('#tutorial-tour[open]').waitFor();
    assert.equal(await counter(), interrupted, 'Início por link também deve preservar retomada em andamento');
    await page.locator('#tutorial-prev').click();
    assert.equal(await page.locator('#tutorial-next').evaluate(el => el === document.activeElement), true,
      'Voltar ao passo 1 deve conservar foco em botão habilitado');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-tutorial-open]').first().evaluate(el => el === document.activeElement), true,
      'Fechar deve devolver foco ao acionador contextual');

    // Alvos opcionais ausentes não podem produzir abertura tardia após cancelar.
    await open();
    await page.locator('[data-tour="filtros"]').evaluate(el => el.remove());
    await help();
    await page.locator('#tutorial-help-body button').filter({ hasText: /^(Fazer o tour|Retomar tour|Reiniciar tour)$/ }).first().click();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(1800); // Limite do antigo localizador; detecta reabertura tardia.
    assert.equal(await page.locator('#tutorial-tour').evaluate(el => el.open), false,
      'Cancelar durante inicialização não pode abrir o tour depois');

    await open('/painel?tutorial=painel&tour=1');
    await page.locator('#tutorial-tour[open]').waitFor();
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-tutorial-open]').first().evaluate(el => el === document.activeElement), true,
      'Tour iniciado por link deve devolver foco a uma ação útil');
    const blocked = await browser.newContext();
    await blocked.addInitScript(() => { Storage.prototype.setItem = () => { throw new Error('storage bloqueado para teste'); }; });
    const blockedPage = await blocked.newPage();
    blockedPage.on('pageerror', e => errors.push(e.message));
    blockedPage.on('request', r => { if (r.method() !== 'GET') writes.push(r.url()); });
    await blockedPage.goto(base + '/painel?role=admin');
    assert.equal(await blockedPage.locator('#tutorial-invite').isVisible(), false);
    await blockedPage.locator('[data-tutorial-open]').first().click();
    assert.match(await blockedPage.locator('#tutorial-help-body').textContent(), /não permite salvar o progresso/);
    assert.equal(await blockedPage.locator('#tutorial-suggestions').isDisabled(), true);
    await blockedPage.locator('#tutorial-help-body button').first().click();
    await blockedPage.locator('#tutorial-tour[open]').waitFor();
    await blockedPage.keyboard.press('Escape');
    await blocked.close();
    assert.deepEqual(errors, [], 'Não deve haver exceções JavaScript');
    assert.deepEqual(writes, [], 'A ajuda não deve alterar dados de negócio');
    console.log('PASS: ciclo, retomada, cancelamento, foco e ausência de alterações de negócio');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
