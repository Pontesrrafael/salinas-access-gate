<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{App, Auth, View};
use App\Services\{Chamados, FormularioDinamico, Upload};

/** Dados fictícios para testar o HTML real sem MariaDB nem contas reais. */
function chamadoFixture(): array
{
    return [
        'id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856810', 'numero' => 42,
        'area_id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856801', 'categoria_id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856820',
        'titulo' => 'Manutenção da iluminação <script>alert(1)</script>', 'descricao' => str_repeat('Descrição longa sem espaços ', 8),
        'status' => 'pendente', 'solicitante_id' => 'fixture-admin', 'solicitante_nome' => 'Pessoa de teste',
        'atendente_id' => null, 'atendente_nome' => null, 'beneficiario_nome' => null, 'recorrente' => 1,
        'urgencia' => 2, 'impacto' => 3, 'prioridade' => 2,
        'created_at' => '2026-10-28 08:00:00', 'prazo_resposta' => '2026-10-28 09:00:00',
        'prazo_solucao' => '2026-10-28 18:00:00', 'pendente_desde' => '2026-10-28 10:00:00',
        'segundos_pendente' => 0, 'respondido_em' => null, 'solucionado_em' => null, 'fechado_em' => null,
        'area_nome' => 'Facilities', 'categoria_nome' => 'Manutenção',
    ];
}

function chamadoViewFixture(string $view, bool $atende = true): array
{
    $c = chamadoFixture();
    $u = ['id' => 'fixture-admin', 'papel' => 'admin', 'nome' => 'Pessoa de teste'];
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $u);
    $areas = [['id' => $c['area_id'], 'nome' => 'Facilities']];
    $categorias = [['id' => $c['categoria_id'], 'nome' => 'Manutenção', 'parent_id' => null, 'caminho' => 'Infraestrutura › Manutenção', 'descricao' => 'Atendimento predial', 'urgencia_padrao' => 3]];
    $membros = [['id' => 'fixture-admin', 'nome' => 'Pessoa de teste']];
    return match ($view) {
        'abrir' => ['titulo' => t('Abrir chamado'), 'areas' => $areas, 'areaId' => $c['area_id'], 'categorias' => $categorias, 'categoriaId' => $c['categoria_id'], 'categoria' => $categorias[0], 'campos' => [], 'old' => []],
        'index' => ['titulo' => t('Fila de atendimento'), 'lista' => [$c], 'filtros' => array_fill_keys(['area_id', 'categoria_id', 'status', 'prioridade', 'atendente_id', 'prazo'], ''), 'fila' => true, 'areas' => $areas, 'categorias' => $categorias, 'membros' => $membros, 'total' => 1, 'pagina' => 1, 'limite' => 50],
        default => ['titulo' => t('Chamado #{numero}', ['numero' => 42]), 'c' => $c, 'u' => $u, 'atende' => $atende, 'eventos' => [
            ['id' => 'ev-1', 'tipo' => 'comentario', 'texto' => '<img src=x onerror=alert(1)>', 'usuario_nome' => 'Pessoa', 'created_at' => $c['created_at']],
            ...($atende ? [['id' => 'ev-2', 'tipo' => 'interno', 'texto' => 'Nota reservada de teste', 'usuario_nome' => 'Pessoa', 'created_at' => $c['created_at']]] : []),
        ], 'anexos' => [['id' => 'fixture-anexo', 'nome_arquivo' => str_repeat('arquivo', 20) . '.pdf', 'tamanho_bytes' => 1024, 'acompanhamento_id' => null]], 'respostas' => [['rotulo' => 'Local <teste>', 'valor' => '<b>Bloco A</b>']], 'membros' => $membros, 'old' => []],
    };
}

// Roteador apenas no servidor de teste, em loopback. Nunca habilitado na aplicação.
// php -S 127.0.0.1:8249 -t public tests/chamados.php
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/assets/')) { return false; }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); return; }
    \App\Core\Http::securityHeaders();
    $view = in_array($_GET['view'] ?? '', ['abrir', 'index', 'detalhe'], true) ? $_GET['view'] : 'detalhe';
    View::render('chamados/' . $view, chamadoViewFixture($view));
    return;
}

$checks = 0;
function expect(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function rejeita(callable $fn, string $label): void
{
    try { $fn(); } catch (InvalidArgumentException) { expect(true, $label); return; }
    throw new RuntimeException($label);
}

$inicio = new DateTimeImmutable('2026-10-28 08:00:00');
expect(Chamados::prioridade(1, 4) === 1, 'Risco crítico tem prioridade máxima.');
expect(Chamados::prioridade(5, 1) === 6, 'Menor urgência/impacto tem prioridade mínima.');
for ($urgencia = 1; $urgencia <= 5; $urgencia++) {
    for ($impacto = 1; $impacto <= 4; $impacto++) {
        $p = Chamados::prioridade($urgencia, $impacto);
        expect($p >= 1 && $p <= 6, 'Matriz cobre todos os pares.');
        if ($impacto < 4) { expect(Chamados::prioridade($urgencia, $impacto + 1) <= $p, 'Mais impacto nunca reduz prioridade.'); }
        if ($urgencia < 5) { expect(Chamados::prioridade($urgencia + 1, $impacto) >= $p, 'Menos urgência nunca aumenta prioridade.'); }
    }
}
foreach ([[0, 1], [6, 1], [1, 0], [1, 5]] as [$u, $i]) { rejeita(fn () => Chamados::prioridade($u, $i), 'Faixas inválidas rejeitadas.'); }
expect(Chamados::prazo($inicio, null) === null, 'SLA não definido fica nulo.');
expect(Chamados::prazo($inicio, 1440)?->format('Y-m-d H:i:s') === '2026-10-29 08:00:00', 'SLA em horas corridas.');
rejeita(fn () => Chamados::prazo($inicio, -1), 'SLA negativo recusado.');
rejeita(fn () => Chamados::prazo($inicio, 0), 'SLA zero recusado.');
$prazo = $inicio->modify('+100 minutes');
expect(Chamados::situacaoPrazo($inicio, $prazo, $inicio->modify('+79 minutes')) === 'no_prazo', 'Antes da janela de 20%.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $inicio->modify('+80 minutes')) === 'vencendo', 'Exatamente 20% restante.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $prazo) === 'vencendo', 'No instante limite.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $prazo->modify('+1 second')) === 'vencido', 'Vencido um segundo depois.');
expect(Chamados::situacaoPrazo($inicio, null, $inicio) === 'no_prazo', 'Sem SLA não vence.');

$c = chamadoFixture();
$agora = new DateTimeImmutable('2026-10-28 12:00:00');
expect(Chamados::prazoSolucao($c, $agora)?->format('H:i') === '20:00', 'Prazo efetivo soma pausa em andamento.');
$mudancas = Chamados::mudarStatus($c, 'atribuido', $agora);
expect($mudancas['segundos_pendente'] === 7200 && $mudancas['pendente_desde'] === null, 'Saída de pendente acumula segundos.');
$c = array_replace($c, $mudancas);
expect(Chamados::prazoSolucao($c, $agora->modify('+1 hour'))?->format('H:i') === '20:00', 'Pausa não é contada duas vezes.');
$c = array_replace($c, Chamados::mudarStatus($c, 'pendente', $agora));
$c = array_replace($c, Chamados::mudarStatus($c, 'solucionado', $agora->modify('+1 hour')));
expect($c['segundos_pendente'] === 10800 && $c['solucionado_em'] === '2026-10-28 13:00:00', 'Múltiplas pausas e solução durante pausa.');
expect(Chamados::situacaoSolucao($c, $agora->modify('+10 days')) === 'no_prazo', 'Solução encerra o relógio.');
expect(!Chamados::podeFecharAutomaticamente($c, new DateTimeImmutable('2026-11-04 12:59:59')), 'Auto fechamento espera sete dias.');
expect(Chamados::podeFecharAutomaticamente($c, new DateTimeImmutable('2026-11-04 13:00:00')), 'Auto fechamento no limite.');
expect(!Chamados::podeFecharAutomaticamente($c, $agora->modify('+30 days'), 0), 'Configuração zero desliga auto fechamento.');
$reaberto = array_replace($c, Chamados::mudarStatus($c, 'atribuido', $agora->modify('+1 day')));
expect($reaberto['solucionado_em'] === null && $reaberto['fechado_em'] === null && $reaberto['segundos_pendente'] === 10800, 'Reabrir preserva pausas e limpa encerramento.');
expect(!Chamados::podeFecharAutomaticamente($reaberto, $agora->modify('+30 days')), 'Reaberto não fecha automaticamente.');
$pendente = chamadoFixture();
$pendente['prazo_solucao'] = '2026-10-28 18:00:00';
$pendente['pendente_desde'] = '2026-10-28 16:00:00';
expect(Chamados::situacaoSolucao($pendente, new DateTimeImmutable('2026-11-01')) === 'vencendo', 'Pausa não altera a janela original de 20%.');
foreach (array_keys(Chamados::STATUS) as $origem) {
    foreach (array_keys(Chamados::STATUS) as $destino) {
        $transicao = Chamados::podeTransicionar($origem, $destino);
        if ($origem === $destino || $origem === 'fechado' || ($destino === 'fechado' && $origem !== 'solucionado')) { expect(!$transicao, 'Transição proibida: ' . $origem . ' → ' . $destino); }
    }
}
foreach (['novo', 'atribuido', 'planejado', 'pendente'] as $status) { expect(Chamados::podeTransicionar($status, 'solucionado'), 'Status ativo aceita solução.'); }
rejeita(fn () => Chamados::mudarStatus(['status' => 'fechado'], 'atribuido', $inicio), 'Fechado é terminal.');
$arvore = Chamados::categoriasEmArvore([['id' => 'b', 'nome' => 'Lâmpadas', 'parent_id' => 'a'], ['id' => 'a', 'nome' => 'Elétrica', 'parent_id' => null]]);
expect($arvore[1]['caminho'] === 'Elétrica › Lâmpadas', 'Select mostra a hierarquia completa.');
expect(count(Chamados::categoriasEmArvore([['id' => 'a', 'nome' => 'A', 'parent_id' => 'b'], ['id' => 'b', 'nome' => 'B', 'parent_id' => 'a']])) === 2, 'Ciclo legado não trava a árvore.');

// Catálogos também cobrem os rótulos dinâmicos que o inventário literal não encontra.
foreach (['en', 'es'] as $locale) {
    $catalog = json_decode(file_get_contents(App::root('app/Lang/' . $locale . '.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ([Chamados::STATUS, Chamados::URGENCIAS, Chamados::EXPLICACOES, Chamados::IMPACTOS, Chamados::PRIORIDADES, Chamados::PRAZOS, Chamados::TIPOS] as $labels) {
        foreach ($labels as $label) { expect(isset($catalog[$label]), $locale . ' traduz ' . $label); }
    }
    foreach (['Serviços', 'Abrir chamado', 'Meus chamados', 'Fila de atendimento'] as $label) { expect(isset($catalog[$label]), 'Menu traduzido: ' . $label); }
}
foreach (['abrir', 'index', 'detalhe'] as $view) {
    $html = View::partial('chamados/' . $view, chamadoViewFixture($view));
    expect(!preg_match('/<(script|style)\b|\s(?:style|onchange|onclick)=/i', $html), 'View compatível com CSP: ' . $view);
    expect(!str_contains($html, '<script>alert(1)</script>') && !str_contains($html, '<img src=x'), 'Dados escapados: ' . $view);
    if ($view === 'detalhe') { expect(str_contains($html, 'Nota reservada de teste'), 'Equipe vê nota interna.'); }
}
$html = View::partial('chamados/detalhe', chamadoViewFixture('detalhe', false));
expect(!str_contains($html, 'Nota reservada de teste') && !str_contains($html, 'value="interno"'), 'Solicitante não recebe nota interna nem controle para criá-la.');
expect(FormularioDinamico::validar([], [], [])['erros'] === [], 'Área sem campos personalizados não gera erro.');
expect(FormularioDinamico::validar([['id' => 'x', 'rotulo' => 'Local', 'tipo' => 'texto', 'obrigatorio' => 1, 'opcoes' => []]], [], [])['erros'] !== [], 'Campo obrigatório vazio é recusado.');

// Arquivos sintéticos locais; não consulta nem modifica arquivos do usuário.
$dir = App::root('storage');
$tmp = tempnam($dir, 'chamados-test-');
try {
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $file = ['name' => 'teste.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];
    expect(Upload::validar([$file], true) === [], 'PDF permitido em chamado.');
    expect(Upload::validar([$file]) !== [], 'PDF continua proibido na portaria.');
    file_put_contents($tmp, str_repeat('x', 4 * 1024 * 1024 + 1));
    expect(Upload::validar([$file], true) !== [], 'Tamanho real acima de 4 MB é recusado mesmo com tamanho declarado menor.');
    file_put_contents($tmp, '<?php echo 1;');
    expect(Upload::validar([$file], true) !== [], 'Arquivo executável disfarçado é recusado.');
    expect(Upload::caminho('../../app/bootstrap.php') === null, 'Download bloqueia path traversal.');
} finally { if (is_file($tmp)) { unlink($tmp); } }

echo "Chamados: {$checks} verificações sem banco OK.\n";
