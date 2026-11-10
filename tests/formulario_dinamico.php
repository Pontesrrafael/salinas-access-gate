<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\ServicosConfigController;
use App\Services\FormularioDinamico;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

$campos = [
    [
        'id'          => 'c-texto',
        'rotulo'      => 'Local exato',
        'tipo'        => 'texto',
        'ajuda'       => 'Informe o bloco ou sala',
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-longo',
        'rotulo'      => 'Detalhamento técnico',
        'tipo'        => 'texto_longo',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-numero',
        'rotulo'      => 'Quantidade de itens',
        'tipo'        => 'numero',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-data',
        'rotulo'      => 'Data desejada',
        'tipo'        => 'data',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-lista',
        'rotulo'      => 'Unidade',
        'tipo'        => 'lista',
        'ajuda'       => null,
        'opcoes'      => json_encode(['Mina NSL', 'Escritório Cuiabá'], JSON_UNESCAPED_UNICODE),
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-multipla',
        'rotulo'      => 'EPIs necessários',
        'tipo'        => 'multipla',
        'ajuda'       => null,
        'opcoes'      => ['Capacete', 'Botina', 'Protetor auricular'],
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-simnao',
        'rotulo'      => 'Há bloqueio de área?',
        'tipo'        => 'sim_nao',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-anexo',
        'rotulo'      => 'Evidência fotográfica',
        'tipo'        => 'anexo',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 1,
    ],
    [
        'id'          => 'c-inativo',
        'rotulo'      => 'Campo desativado',
        'tipo'        => 'texto',
        'ajuda'       => null,
        'opcoes'      => null,
        'obrigatorio' => 1,
        'ativo'       => 0,
    ],
];

// Cria arquivos temporários para testar validação real de anexo via Upload::validar()
$tmpPng = tempnam(sys_get_temp_dir(), 'sag_png_');
$tmpTxt = tempnam(sys_get_temp_dir(), 'sag_txt_');

try {
    // PNG 1x1 válido
    $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';
    file_put_contents($tmpPng, base64_decode($pngBase64));
    file_put_contents($tmpTxt, 'conteudo texto puro invalido como imagem');

    // 1. Obrigatórios vazios devem falhar todos os 8 campos ativos (e ignorar o inativo)
    $vazio = FormularioDinamico::validar($campos, [], []);
    expect(count($vazio['erros']) === 8, 'Todos os 8 campos obrigatórios ativos devem acusar erro quando vazios.');
    expect(!array_key_exists('c-inativo', $vazio['valores']), 'Campo inativo deve ser ignorado na validação.');

    // 2. Validação com todos os tipos preenchidos corretamente (formato $_POST['campos'] e $_FILES['campos'])
    $entradaOk = [
        'campos' => [
            'c-texto'    => '  Oficina Mecânica - Galpão 2  ',
            'c-longo'    => "Lâmpada queimada no setor norte.\nNecessário andaime.",
            'c-numero'   => '12,5',
            'c-data'     => '2026-10-28',
            'c-lista'    => 'Mina NSL',
            'c-multipla' => ['Capacete', 'Botina'],
            'c-simnao'   => 'sim',
        ],
    ];
    $arquivosOk = [
        'campos' => [
            'name'     => ['c-anexo' => 'evidencia.png'],
            'tmp_name' => ['c-anexo' => $tmpPng],
            'error'    => ['c-anexo' => UPLOAD_ERR_OK],
            'size'     => ['c-anexo' => filesize($tmpPng) ?: 68],
        ],
    ];
    $resOk = FormularioDinamico::validar($campos, $entradaOk, $arquivosOk);
    expect($resOk['erros'] === [], 'Entrada válida não deve gerar erros: ' . implode(' | ', $resOk['erros']));
    expect($resOk['valores']['c-texto'] === 'Oficina Mecânica - Galpão 2', 'Texto curto deve sofrer trim.');
    expect($resOk['valores']['c-numero'] === '12.5', 'Número com vírgula decimal deve ser normalizado.');
    expect($resOk['valores']['c-data'] === '2026-10-28', 'Data Y-m-d válida deve ser preservada.');
    expect($resOk['valores']['c-lista'] === 'Mina NSL', 'Opção válida da lista deve ser aceita.');
    expect($resOk['valores']['c-multipla'] === 'Capacete, Botina', 'Múltipla escolha válida deve ser consolidada.');
    expect($resOk['valores']['c-simnao'] === 'sim', 'Sim/Não deve ser normalizado.');
    expect($resOk['valores']['c-anexo'] === 'evidencia.png', 'Nome do anexo válido deve constar nos valores.');

    // 3. Recusa de valores inválidos por tipo e opções fora da lista
    $entradaInvalida = [
        'c-texto'    => str_repeat('A', 501),
        'c-longo'    => 'ok',
        'c-numero'   => 'nao-eh-numero',
        'c-data'     => '2026-02-30',
        'c-lista'    => 'Unidade Inexistente',
        'c-multipla' => ['Capacete', 'Opcao Forjada'],
        'c-simnao'   => 'talvez',
    ];
    $arquivosInvalidos = [
        'c-anexo' => [
            'name'     => 'falso.jpg',
            'tmp_name' => $tmpTxt,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpTxt) ?: 40,
        ],
    ];
    $resInv = FormularioDinamico::validar($campos, $entradaInvalida, $arquivosInvalidos);
    expect(count($resInv['erros']) === 7, 'Todos os 7 campos com dados inválidos (texto > 500, número, data 30/02, lista fora, múltipla fora, sim_nao, anexo falso) devem falhar.');

    // 4. Campos opcionais vazios devem passar sem erro
    $camposOpcionais = array_map(static function (array $c): array {
        $c['obrigatorio'] = 0;
        return $c;
    }, $campos);
    $resOpc = FormularioDinamico::validar($camposOpcionais, [], []);
    expect($resOpc['erros'] === [], 'Campos opcionais em branco não devem gerar erros.');

    // 5. Escape de HTML no renderizar (prevenção de XSS e conformidade CSP)
    $camposXss = [
        [
            'id'          => 'xss-1',
            'rotulo'      => '<script>alert("rotulo")</script>',
            'tipo'        => 'texto',
            'ajuda'       => '<img src=x onerror="alert(1)">',
            'opcoes'      => null,
            'obrigatorio' => 1,
            'ativo'       => 1,
        ],
        [
            'id'          => 'xss-2',
            'rotulo'      => 'Escolha segura',
            'tipo'        => 'lista',
            'ajuda'       => null,
            'opcoes'      => ['<b>Opção 1</b>', '" onload="alert(2)'],
            'obrigatorio' => 0,
            'ativo'       => 1,
        ],
    ];
    $html = FormularioDinamico::renderizar($camposXss, [
        'xss-1' => '"><svg onload=alert(3)>',
        'xss-2' => '<b>Opção 1</b>',
    ]);
    expect(!str_contains($html, '<script>'), 'renderizar() deve escapar tags <script> no rótulo.');
    expect(!str_contains($html, '<img'), 'renderizar() deve escapar tags <img> na ajuda.');
    expect(!str_contains($html, '<svg'), 'renderizar() deve escapar valores preenchidos pelo usuário.');
    expect(!str_contains($html, '<b>Opção 1</b>'), 'renderizar() deve escapar HTML nas opções da lista.');
    expect(str_contains($html, '&lt;script&gt;alert(&quot;rotulo&quot;)&lt;/script&gt;'), 'Entidades HTML escapadas devem aparecer no output.');
    expect(!str_contains($html, 'style='), 'renderizar() não deve gerar atributo style inline (CSP).');

    // 6. Árvore de categorias e prevenção de ciclos no ServicosConfigController
    $cats = [
        ['id' => 'cat-filho', 'parent_id' => 'cat-pai', 'nome' => 'Iluminação'],
        ['id' => 'cat-pai',   'parent_id' => null,      'nome' => 'Elétrica'],
        ['id' => 'cat-neto',  'parent_id' => 'cat-filho', 'nome' => 'Lâmpada LED'],
    ];
    $arvore = ServicosConfigController::arvoreCategorias($cats);
    expect(count($arvore) === 3, 'Árvore deve conter as 3 categorias.');
    expect($arvore[0]['id'] === 'cat-pai' && $arvore[0]['nivel'] === 0, 'Raiz deve vir primeiro com nível 0.');
    expect($arvore[1]['id'] === 'cat-filho' && $arvore[1]['nivel'] === 1, 'Filho deve vir em seguida com nível 1.');
    expect($arvore[2]['id'] === 'cat-neto' && $arvore[2]['nivel'] === 2 && $arvore[2]['caminho'] === 'Elétrica › Iluminação › Lâmpada LED', 'Neto deve ter nível 2 e caminho completo.');
    expect(ServicosConfigController::criaCicloCategoria($cats, 'cat-pai', 'cat-neto'), 'Tornar pai filho de seu neto deve ser detectado como ciclo.');
    expect(!ServicosConfigController::criaCicloCategoria($cats, 'cat-neto', 'cat-pai'), 'Subir neto para filho direto da raiz não forma ciclo.');
} finally {
    @unlink($tmpPng);
    @unlink($tmpTxt);
}


// Campos de upload dinâmicos conservam o arquivo normalizado para persistência no controller.
$tmpPdf = tempnam(__DIR__ . '/../storage', 'pdf-');
$tmpGrande = tempnam(__DIR__ . '/../storage', 'grande-');
try {
    file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
    $campoPdf = [['id' => 'pdf', 'rotulo' => 'Comprovante', 'tipo' => 'anexo', 'obrigatorio' => 1]];
    $f = ['name' => 'comprovante.pdf', 'tmp_name' => $tmpPdf, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpPdf)];
    $validado = FormularioDinamico::validar($campoPdf, [], ['campos' => ['name' => ['pdf' => $f['name']], 'tmp_name' => ['pdf' => $tmpPdf], 'error' => ['pdf' => 0], 'size' => ['pdf' => $f['size']]]]);
    expect($validado['erros'] === [] && $validado['arquivos']['pdf'] === [$f], 'PDF dinâmico válido sai vinculado ao campo para persistência.');
    file_put_contents($tmpGrande, str_repeat('x', 4 * 1024 * 1024 + 1));
    $grande = array_replace($f, ['tmp_name' => $tmpGrande, 'size' => 1]);
    expect(FormularioDinamico::validar($campoPdf, [], ['pdf' => $grande])['erros'] !== [], 'Tamanho real superior a 4 MB é recusado mesmo com metadados falsos.');
    $limite = \App\Core\App::config('uploads')['max_files'];
    expect(\App\Services\Upload::validar(array_fill(0, $limite + 1, $f), true) !== [], 'Limite agregado de anexos impede ultrapassar o máximo do envio.');
    $htmlMultipla = FormularioDinamico::renderizar([['id' => 'm', 'rotulo' => 'Equipamentos', 'tipo' => 'multipla', 'opcoes' => ['A', 'B'], 'obrigatorio' => 1]]);
    expect(str_contains($htmlMultipla, '<fieldset') && str_contains($htmlMultipla, '<legend>Equipamentos'), 'Grupo múltiplo tem fieldset e legend.');
    expect(str_contains($htmlMultipla, 'aria-hidden="true"'), 'Asterisco decorativo não é anunciado.');
    expect(FormularioDinamico::pendentes([['id' => 'antigo'], ['id' => 'novo']], ['antigo']) === [['id' => 'novo']], 'Transferência pede campos novos e preserva respostas históricas.');
} finally { @unlink($tmpPdf); @unlink($tmpGrande); }

echo "FormularioDinamico: validação dos 8 tipos, obrigatórios, opções fora da lista, escape HTML e árvore de categorias OK.\n";
