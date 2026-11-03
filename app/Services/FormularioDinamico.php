<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Construtor, renderizador e validador de formulários dinâmicos por área e categoria de chamado.
 */
final class FormularioDinamico
{
    public const TIPOS = [
        'texto'       => 'Texto curto',
        'texto_longo' => 'Texto longo',
        'numero'      => 'Número',
        'data'        => 'Data',
        'lista'       => 'Lista (escolha única)',
        'multipla'    => 'Múltipla escolha',
        'sim_nao'     => 'Sim / Não',
        'anexo'       => 'Anexo',
    ];

    /**
     * Lista os campos ativos da área (gerais + específicos da categoria quando informada).
     * @return array<int, array<string, mixed>>
     */
    public static function campos(string $areaId, ?string $categoriaId = null): array
    {
        $catId = $categoriaId !== null ? trim($categoriaId) : '';
        if ($catId === '') {
            $rows = Database::all(
                'SELECT * FROM servico_campos
                  WHERE area_id = ? AND ativo = 1 AND categoria_id IS NULL
                  ORDER BY ordem ASC, rotulo ASC',
                [$areaId]
            );
        } else {
            $rows = Database::all(
                'SELECT * FROM servico_campos
                  WHERE area_id = ? AND ativo = 1 AND (categoria_id IS NULL OR categoria_id = ?)
                  ORDER BY CASE WHEN categoria_id IS NULL THEN 0 ELSE 1 END, ordem ASC, rotulo ASC',
                [$areaId, $catId]
            );
        }

        foreach ($rows as &$row) {
            $row['opcoes'] = self::decodificarOpcoes($row['opcoes'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /** Na transferência, respostas históricas continuam intactas; coleta só perguntas ainda não respondidas. */
    public static function pendentes(array $campos, array $respondidos): array
    {
        return array_values(array_filter($campos, static fn ($campo) => !in_array($campo['id'], $respondidos, true)));
    }

    /**
     * Renderiza o HTML dos campos dinâmicos com toda saída escapada via e().
     * Rótulos, ajudas e opções cadastrados pelo Admin são dados e não passam por t().
     *
     * @param array<int, array<string, mixed>> $campos
     * @param array<string, mixed> $valores
     */
    public static function renderizar(array $campos, array $valores = []): string
    {
        $blocos = [];

        foreach ($campos as $c) {
            if (isset($c['ativo']) && (int) $c['ativo'] === 0) {
                continue;
            }
            $id = trim((string) ($c['id'] ?? ''));
            $rotulo = trim((string) ($c['rotulo'] ?? ''));
            if ($id === '' || $rotulo === '') {
                continue;
            }

            $tipo = (string) ($c['tipo'] ?? 'texto');
            $obrigatorio = !empty($c['obrigatorio']);
            $ajuda = trim((string) ($c['ajuda'] ?? ''));
            $opcoes = self::decodificarOpcoes($c['opcoes'] ?? null);
            $valorBruto = self::extrairValor($valores, $id);

            $reqSpan = $obrigatorio ? ' <span class="obrigatorio" aria-hidden="true">*</span>' : '';
            $reqAttr = $obrigatorio ? ' required' : '';
            $ajudaHtml = $ajuda !== '' ? '<small>' . e($ajuda) . '</small>' : '';
            $name = 'campos[' . e($id) . ']';

            switch ($tipo) {
                case 'texto_longo':
                    $valStr = is_scalar($valorBruto) ? (string) $valorBruto : '';
                    $blocos[] = '<label class="campo campo-largo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<textarea name="' . $name . '" rows="3" maxlength="5000"' . $reqAttr . '>' . e($valStr) . '</textarea>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'numero':
                    $valStr = is_scalar($valorBruto) ? (string) $valorBruto : '';
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<input type="number" step="any" name="' . $name . '" value="' . e($valStr) . '"' . $reqAttr . '>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'data':
                    $valStr = is_scalar($valorBruto) ? (string) $valorBruto : '';
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<input type="date" name="' . $name . '" value="' . e($valStr) . '"' . $reqAttr . '>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'lista':
                    $valStr = is_scalar($valorBruto) ? (string) $valorBruto : '';
                    $optsHtml = '<option value="">' . e(t('— Selecione —')) . '</option>';
                    foreach ($opcoes as $op) {
                        $optsHtml .= '<option value="' . e($op) . '"' . selected($valStr, $op) . '>' . e($op) . '</option>';
                    }
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<select name="' . $name . '"' . $reqAttr . '>' . $optsHtml . '</select>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'multipla':
                    $selecionadas = self::normalizarMultipla($valorBruto);
                    $checksHtml = '';
                    foreach ($opcoes as $op) {
                        $chk = in_array($op, $selecionadas, true) ? ' checked' : '';
                        $checksHtml .= '<label class="checkbox"><input type="checkbox" name="' . $name . '[]" value="' . e($op) . '"' . $chk . '> <span>' . e($op) . '</span></label>';
                    }
                    $blocos[] = '<fieldset class="campo campo-largo"><legend>' . e($rotulo) . $reqSpan . '</legend>'
                        . $checksHtml . $ajudaHtml . '</fieldset>';
                    break;

                case 'sim_nao':
                    $valNorm = self::normalizarSimNao(is_scalar($valorBruto) ? (string) $valorBruto : '');
                    $optsHtml = '<option value="">' . e(t('— Selecione —')) . '</option>'
                        . '<option value="sim"' . selected($valNorm, 'sim') . '>' . e(t('Sim')) . '</option>'
                        . '<option value="nao"' . selected($valNorm, 'nao') . '>' . e(t('Não')) . '</option>';
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<select name="' . $name . '"' . $reqAttr . '>' . $optsHtml . '</select>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'anexo':
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<input type="file" name="' . $name . '" accept=".jpg,.jpeg,.png,.webp,.pdf"' . $reqAttr . '>'
                        . $ajudaHtml . '</label>';
                    break;

                case 'texto':
                default:
                    $valStr = is_scalar($valorBruto) ? (string) $valorBruto : '';
                    $blocos[] = '<label class="campo"><span>' . e($rotulo) . $reqSpan . '</span>'
                        . '<input type="text" name="' . $name . '" maxlength="500" value="' . e($valStr) . '"' . $reqAttr . '>'
                        . $ajudaHtml . '</label>';
                    break;
            }
        }

        if ($blocos === []) {
            return '';
        }

        return '<div class="grade-2 compacta">' . implode('', $blocos) . '</div>';
    }

    /**
     * Valida os valores e arquivos submetidos contra a definição dos campos.
     *
     * @param array<int, array<string, mixed>> $campos
     * @param array<string, mixed> $entrada
     * @param array<string, mixed> $arquivos
     * @return array{valores: array<string, string>, erros: string[], arquivos: array<string, array>}
     */
    public static function validar(array $campos, array $entrada, array $arquivos = []): array
    {
        $valores = [];
        $erros = [];
        $uploads = [];

        foreach ($campos as $c) {
            if (isset($c['ativo']) && (int) $c['ativo'] === 0) {
                continue;
            }
            $id = trim((string) ($c['id'] ?? ''));
            $rotulo = trim((string) ($c['rotulo'] ?? $id));
            if ($id === '') {
                continue;
            }

            $tipo = (string) ($c['tipo'] ?? 'texto');
            $obrigatorio = !empty($c['obrigatorio']);
            $opcoes = self::decodificarOpcoes($c['opcoes'] ?? null);

            if ($tipo === 'anexo') {
                $listaArquivos = self::extrairArquivos($arquivos, $id);
                if ($listaArquivos === []) {
                    if ($obrigatorio) {
                        $erros[] = t('O campo "{campo}" é obrigatório.', ['campo' => $rotulo]);
                    }
                    $valores[$id] = '';
                    continue;
                }
                $errosUpload = Upload::validar($listaArquivos, true);
                if ($errosUpload !== []) {
                    foreach ($errosUpload as $msg) {
                        $erros[] = $rotulo . ': ' . $msg;
                    }
                    $valores[$id] = '';
                    continue;
                }
                $nomes = array_map(
                    static fn (array $f): string => mb_substr(basename((string) ($f['name'] ?? '')), 0, 200),
                    $listaArquivos
                );
                $valores[$id] = implode(', ', $nomes);
                $uploads[$id] = $listaArquivos;
                continue;
            }

            $bruto = self::extrairValor($entrada, $id);

            if ($tipo === 'multipla') {
                $selecionadas = self::normalizarMultipla($bruto);
                if ($selecionadas === []) {
                    if ($obrigatorio) {
                        $erros[] = t('O campo "{campo}" é obrigatório.', ['campo' => $rotulo]);
                    }
                    $valores[$id] = '';
                    continue;
                }
                $invalida = false;
                foreach ($selecionadas as $item) {
                    if (!in_array($item, $opcoes, true)) {
                        $invalida = true;
                        break;
                    }
                }
                if ($invalida || $opcoes === []) {
                    $erros[] = t('Opção inválida no campo "{campo}".', ['campo' => $rotulo]);
                    $valores[$id] = '';
                    continue;
                }
                $valores[$id] = implode(', ', $selecionadas);
                continue;
            }

            if (is_array($bruto)) {
                $erros[] = t('Valor inválido no campo "{campo}".', ['campo' => $rotulo]);
                $valores[$id] = '';
                continue;
            }

            $str = is_scalar($bruto) ? trim((string) $bruto) : '';
            if ($str === '') {
                if ($obrigatorio) {
                    $erros[] = t('O campo "{campo}" é obrigatório.', ['campo' => $rotulo]);
                }
                $valores[$id] = '';
                continue;
            }

            switch ($tipo) {
                case 'texto':
                    if (mb_strlen($str) > 500) {
                        $erros[] = t('O campo "{campo}" excede o tamanho máximo.', ['campo' => $rotulo]);
                    }
                    $valores[$id] = mb_substr($str, 0, 500);
                    break;

                case 'texto_longo':
                    if (mb_strlen($str) > 5000) {
                        $erros[] = t('O campo "{campo}" excede o tamanho máximo.', ['campo' => $rotulo]);
                    }
                    $valores[$id] = mb_substr($str, 0, 5000);
                    break;

                case 'numero':
                    $norm = str_replace(',', '.', $str);
                    if (!is_numeric($norm) || !is_finite((float) $norm)) {
                        $erros[] = t('Informe um número válido no campo "{campo}".', ['campo' => $rotulo]);
                        $valores[$id] = '';
                        break;
                    }
                    $valores[$id] = $norm;
                    break;

                case 'data':
                    $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)
                        ? DateTimeImmutable::createFromFormat('!Y-m-d', $str)
                        : false;
                    if (!$dt || $dt->format('Y-m-d') !== $str) {
                        $erros[] = t('Informe uma data válida no campo "{campo}".', ['campo' => $rotulo]);
                        $valores[$id] = '';
                        break;
                    }
                    $valores[$id] = $str;
                    break;

                case 'lista':
                    if (!in_array($str, $opcoes, true)) {
                        $erros[] = t('Opção inválida no campo "{campo}".', ['campo' => $rotulo]);
                        $valores[$id] = '';
                        break;
                    }
                    $valores[$id] = $str;
                    break;

                case 'sim_nao':
                    $norm = self::normalizarSimNao($str);
                    if ($norm === '') {
                        $erros[] = t('Selecione Sim ou Não no campo "{campo}".', ['campo' => $rotulo]);
                        $valores[$id] = '';
                        break;
                    }
                    $valores[$id] = $norm;
                    break;

                default:
                    $erros[] = t('Tipo de campo inválido em "{campo}".', ['campo' => $rotulo]);
                    $valores[$id] = '';
                    break;
            }
        }

        return ['valores' => $valores, 'erros' => $erros, 'arquivos' => $uploads];
    }

    /**
     * Converte opções (JSON do banco, array em memória ou texto multilinhas) em lista de strings.
     * @return string[]
     */
    public static function decodificarOpcoes(mixed $opcoes): array
    {
        if (is_string($opcoes)) {
            $trim = trim($opcoes);
            if ($trim === '') {
                return [];
            }
            if (str_starts_with($trim, '[')) {
                $dec = json_decode($trim, true);
                $opcoes = is_array($dec) ? $dec : [];
            } else {
                $opcoes = preg_split('/\R+/', $trim) ?: [];
            }
        }
        if (!is_array($opcoes)) {
            return [];
        }
        $out = [];
        foreach ($opcoes as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = mb_substr($s, 0, 200);
            }
        }
        return $out;
    }

    /**
     * Procura o valor de um campo tanto em $fonte[$id] quanto em $fonte['campos'][$id] ou $fonte['campo_' . $id].
     */
    private static function extrairValor(array $fonte, string $id): mixed
    {
        if (array_key_exists($id, $fonte)) {
            return $fonte[$id];
        }
        if (isset($fonte['campos']) && is_array($fonte['campos']) && array_key_exists($id, $fonte['campos'])) {
            return $fonte['campos'][$id];
        }
        if (array_key_exists('campo_' . $id, $fonte)) {
            return $fonte['campo_' . $id];
        }
        return null;
    }

    /**
     * @return string[]
     */
    private static function normalizarMultipla(mixed $bruto): array
    {
        if (is_string($bruto)) {
            $trim = trim($bruto);
            if ($trim === '') {
                return [];
            }
            if (str_starts_with($trim, '[')) {
                $dec = json_decode($trim, true);
                if (is_array($dec)) {
                    $bruto = $dec;
                }
            }
            if (is_string($bruto)) {
                $bruto = array_map('trim', explode(',', $trim));
            }
        }
        if (!is_array($bruto)) {
            return [];
        }
        $out = [];
        foreach ($bruto as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private static function normalizarSimNao(string $val): string
    {
        $v = mb_strtolower(trim($val));
        if (in_array($v, ['sim', '1', 's', 'true'], true)) {
            return 'sim';
        }
        if (in_array($v, ['nao', 'não', '0', 'n', 'false'], true)) {
            return 'nao';
        }
        return '';
    }

    /**
     * Normaliza arquivos enviados para um campo em lista no formato esperado por Upload::validar().
     * @return array<int, array{name:string,tmp_name:string,error:int,size:int}>
     */
    private static function extrairArquivos(array $arquivos, string $id): array
    {
        $candidato = null;
        if (isset($arquivos[$id]) && is_array($arquivos[$id])) {
            $candidato = $arquivos[$id];
        } elseif (isset($arquivos['campo_' . $id]) && is_array($arquivos['campo_' . $id])) {
            $candidato = $arquivos['campo_' . $id];
        } elseif (isset($arquivos['campos']) && is_array($arquivos['campos'])) {
            $c = $arquivos['campos'];
            if (isset($c[$id]) && is_array($c[$id])) {
                $candidato = $c[$id];
            } elseif (isset($c['name']) && is_array($c['name']) && array_key_exists($id, $c['name'])) {
                $candidato = [
                    'name'     => $c['name'][$id] ?? '',
                    'tmp_name' => $c['tmp_name'][$id] ?? '',
                    'error'    => $c['error'][$id] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $c['size'][$id] ?? 0,
                ];
            }
        }

        if ($candidato === null) {
            return [];
        }

        // Caso 1: arquivo único ['name' => string, 'tmp_name' => string, ...]
        if (isset($candidato['name']) && !is_array($candidato['name'])) {
            $err = (int) ($candidato['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                return [];
            }
            return [[
                'name'     => (string) ($candidato['name'] ?? ''),
                'tmp_name' => (string) ($candidato['tmp_name'] ?? ''),
                'error'    => $err,
                'size'     => (int) ($candidato['size'] ?? 0),
            ]];
        }

        // Caso 2: estrutura $_FILES múltipla ['name' => [...], 'tmp_name' => [...], ...]
        if (isset($candidato['name']) && is_array($candidato['name'])) {
            $out = [];
            foreach ($candidato['name'] as $i => $name) {
                $err = (int) ($candidato['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[] = [
                    'name'     => (string) $name,
                    'tmp_name' => (string) ($candidato['tmp_name'][$i] ?? ''),
                    'error'    => $err,
                    'size'     => (int) ($candidato['size'][$i] ?? 0),
                ];
            }
            return $out;
        }

        // Caso 3: lista de arrays de arquivos já normalizados
        $out = [];
        foreach ($candidato as $item) {
            if (!is_array($item)) {
                continue;
            }
            $err = (int) ($item['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) ($item['name'] ?? ''),
                'tmp_name' => (string) ($item['tmp_name'] ?? ''),
                'error'    => $err,
                'size'     => (int) ($item['size'] ?? 0),
            ];
        }
        return $out;
    }
}
