<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Gráficos renderizados no servidor em HTML/CSS puro (compatível com a CSP: sem script ou estilo inline).
 * Alturas e larguras usam classes .vh-0…100 / .vw-0…100 definidas em app.css.
 * Cada gráfico traz legenda (2+ séries), dica ao passar o mouse (data-tip/title) e tabela de dados.
 */
final class Grafico
{
    /**
     * Colunas (empilhadas quando há mais de uma série).
     * @param list<string> $rotulos  rótulo curto do eixo X
     * @param list<string> $dicas    rótulo completo para dica/tabela
     * @param array<string, list<int>> $series chave da série => valores
     * @param array<string, string> $nomes chave da série => nome exibido
     */
    public static function colunas(string $titulo, array $rotulos, array $dicas, array $series, array $nomes, string $unidade = 'entradas'): string
    {
        $n = count($rotulos);
        $totais = array_fill(0, $n, 0);
        foreach ($series as $vals) {
            foreach ($vals as $i => $v) {
                $totais[$i] += $v;
            }
        }
        $max = $totais ? max($totais) : 0;
        [$passo, $topo] = self::escala($max);
        $somaGeral = array_sum($totais);
        $multi = count($series) > 1;

        $h = '<figure class="viz" aria-label="' . e($titulo) . '">';
        if ($multi) {
            $h .= self::legenda($series, $nomes);
        }
        $largo = $n > 31 ? ' viz-largo' : '';
        $h .= '<div class="viz-rolagem"><div class="viz-colunas' . $largo . '" role="img" aria-label="'
            . e($titulo . ': ' . self::num($somaGeral) . ' ' . $unidade . ' no período') . '">';

        // Eixo Y (5 marcas: 0, 25, 50, 75, 100 % do topo)
        $h .= '<div class="viz-eixo-y" aria-hidden="true">';
        for ($t = 4; $t >= 0; $t--) {
            $h .= '<span class="viz-y viz-y-' . $t . '">' . self::compacto($passo * $t) . '</span>';
        }
        $h .= '</div><div class="viz-plot"><div class="viz-grade" aria-hidden="true"><i></i><i></i><i></i><i></i></div><div class="viz-barras">';

        foreach ($rotulos as $i => $_) {
            $partes = [];
            $segs = '';
            foreach ($series as $k => $vals) {
                $v = (int) ($vals[$i] ?? 0);
                if ($multi) {
                    $partes[] = ($nomes[$k] ?? $k) . ' ' . self::num($v);
                }
                if ($v > 0) {
                    $segs .= '<span class="viz-seg serie-' . e($k) . ' vh-' . self::pct($v, $topo) . '"></span>';
                }
            }
            $dica = ($dicas[$i] ?? '') . ' — ' . self::num($totais[$i]) . ' ' . $unidade . ($multi && $totais[$i] > 0 ? ' (' . implode(' · ', $partes) . ')' : '');
            $h .= '<div class="viz-col" data-tip="' . e($dica) . '" title="' . e($dica) . '">' . $segs . '</div>';
        }
        $h .= '</div></div>';

        // Eixo X (no máximo ~8 rótulos visíveis)
        $salto = max(1, (int) ceil($n / 8));
        $h .= '<div class="viz-eixo-x" aria-hidden="true">';
        foreach ($rotulos as $i => $r) {
            $h .= '<span>' . ($i % $salto === 0 ? e($r) : '') . '</span>';
        }
        $h .= '</div></div></div>';

        // Tabela acessível
        $h .= '<details class="viz-tabela"><summary>Ver dados em tabela</summary><div class="tabela-rolagem"><table class="tabela"><thead><tr><th>Intervalo</th>';
        foreach ($series as $k => $_) {
            $h .= '<th class="num">' . e($nomes[$k] ?? $k) . '</th>';
        }
        $h .= ($multi ? '<th class="num">Total</th>' : '') . '</tr></thead><tbody>';
        foreach ($rotulos as $i => $_) {
            if ($totais[$i] === 0 && $n > 31) {
                continue; // períodos longos: omite linhas zeradas
            }
            $h .= '<tr><td>' . e($dicas[$i] ?? '') . '</td>';
            foreach ($series as $vals) {
                $h .= '<td class="num">' . self::num((int) ($vals[$i] ?? 0)) . '</td>';
            }
            $h .= ($multi ? '<td class="num"><strong>' . self::num($totais[$i]) . '</strong></td>' : '') . '</tr>';
        }
        $h .= '</tbody></table></div></details></figure>';
        return $h;
    }

    /**
     * Barras horizontais (uma série). Cada item: rotulo (texto) ou rotuloHtml (já escapado), valor, dica?, extra? (texto).
     * @param list<array{rotulo?: string, rotuloHtml?: string, valor: int, dica?: string, extra?: string}> $itens
     */
    public static function barras(string $titulo, array $itens, string $serie = 'unica', string $vazio = 'Sem dados no período.'): string
    {
        if (!$itens || max(array_column($itens, 'valor')) <= 0) {
            return '<p class="mudo vazio">' . e($vazio) . '</p>';
        }
        $max = max(array_column($itens, 'valor'));
        $h = '<ul class="viz-hbarras" aria-label="' . e($titulo) . '">';
        foreach ($itens as $it) {
            $v = (int) $it['valor'];
            $rot = $it['rotuloHtml'] ?? e($it['rotulo'] ?? '');
            $dica = $it['dica'] ?? (($it['rotulo'] ?? '') . ': ' . self::num($v));
            $pct = $v > 0 ? max(1, (int) round($v / $max * (isset($it['extra']) ? 62 : 75))) : 0;
            $h .= '<li data-tip="' . e($dica) . '" title="' . e($dica) . '">'
                . '<span class="viz-h-rotulo">' . $rot . '</span>'
                . '<span class="viz-h-trilho"><span class="viz-h-barra serie-' . e($serie) . ' vw-' . $pct . '"></span>'
                . '<span class="viz-h-valor">' . self::num($v) . (isset($it['extra']) ? ' <small class="mudo">' . e($it['extra']) . '</small>' : '') . '</span></span>'
                . '</li>';
        }
        return $h . '</ul>';
    }

    /** Legenda com amostra de cor ao lado do texto (o texto nunca usa a cor da série). */
    public static function legenda(array $series, array $nomes): string
    {
        $h = '<ul class="viz-legenda">';
        foreach (array_keys($series) as $k) {
            $h .= '<li><span class="viz-chave serie-' . e($k) . '" aria-hidden="true"></span>' . e($nomes[$k] ?? $k) . '</li>';
        }
        return $h . '</ul>';
    }

    /** Escala "redonda" com 4 divisões inteiras: [passo, topo]. */
    public static function escala(int $max): array
    {
        if ($max <= 4) {
            return [1, 4];
        }
        $bruto = $max / 4;
        $mag = 10 ** (int) floor(log10($bruto));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $passo = $m * $mag;
            if ($passo >= $bruto && floor($passo) == $passo) {
                return [(int) $passo, (int) ($passo * 4)];
            }
        }
        $passo = (int) ceil($bruto);
        return [$passo, $passo * 4];
    }

    public static function pct(int $v, int $topo): int
    {
        if ($v <= 0 || $topo <= 0) {
            return 0;
        }
        return max(1, min(100, (int) round($v / $topo * 100)));
    }

    public static function num(int|float $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** 0 / 950 / 1,2 mil / 12 mil */
    public static function compacto(int $n): string
    {
        if ($n < 1000) {
            return (string) $n;
        }
        $v = $n / 1000;
        return ($v < 10 ? rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') : number_format($v, 0, ',', '.')) . ' mil';
    }
}
