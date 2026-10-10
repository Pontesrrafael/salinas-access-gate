<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;

/**
 * Consultas do painel de Indicadores (modo dashboard / Modo TV).
 *
 * Período: [ini, fim) — fim é exclusivo. Horário de Mato Grosso – UTC-4 (sessão do banco e do PHP).
 * Indicadores "agora" (presença, pendências, documentos) não dependem do período; os demais sim.
 */
final class Indicadores
{
    public const PRESETS = [
        'hoje' => 'Hoje', '24h' => 'Últimas 24 horas', '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias', '90d' => 'Últimos 90 dias', 'personalizado' => 'Personalizado',
    ];
    /** Intervalos de atualização automática (segundos => rótulo). */
    public const ATUALIZACOES = [0 => 'Desligada', 5 => '5 s', 10 => '10 s', 30 => '30 s', 60 => '1 min', 300 => '5 min'];
    public const ATUALIZACAO_PADRAO = 60;
    public const MAX_DIAS = 366;
    /** Categorias exibidas nos gráficos (ordem fixa = ordem das cores). */
    public const SERIES = ['colaborador' => 'Colaborador', 'visitante' => 'Visitante', 'terceiro' => 'Terceiro', 'veiculo' => 'Só veículo'];

    private const FMT = 'Y-m-d H:i:s';

    // ------------------------------------------------------------------ filtro

    /**
     * Interpreta os parâmetros da tela. Retorna:
     * preset, ini, fim (DateTimeImmutable, fim exclusivo), localId, gran (hora|dia|semana),
     * antIni/antFim (período anterior de mesma duração), aviso (string|null).
     */
    public static function filtro(array $in, ?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable('now');
        $hoje = $agora->setTime(0, 0);
        $preset = (string) ($in['periodo'] ?? '');
        $desdeTxt = trim((string) ($in['desde'] ?? ''));
        $ateTxt = trim((string) ($in['ate'] ?? ''));
        if (!isset(self::PRESETS[$preset])) {
            $preset = $desdeTxt !== '' ? 'personalizado' : 'hoje';
        }
        $aviso = null;
        $fimAgora = $agora->setTime((int) $agora->format('H'), (int) $agora->format('i'))->add(new DateInterval('PT1M'));

        switch ($preset) {
            case '24h': $ini = $agora->sub(new DateInterval('PT24H')); $fim = $fimAgora; break;
            case '7d':  $ini = $hoje->sub(new DateInterval('P6D'));   $fim = $fimAgora; break;
            case '30d': $ini = $hoje->sub(new DateInterval('P29D'));  $fim = $fimAgora; break;
            case '90d': $ini = $hoje->sub(new DateInterval('P89D'));  $fim = $fimAgora; break;
            case 'personalizado':
                $ini = self::lerData($desdeTxt);
                if ($ini === null) {
                    $aviso = 'Informe uma data válida em "A partir de". Exibindo o dia de hoje.';
                    $preset = 'hoje';
                    $ini = $hoje;
                    $fim = $fimAgora;
                    break;
                }
                $ate = $ateTxt === '' ? null : self::lerData($ateTxt);
                $fim = $ate === null ? $fimAgora : $ate->add(new DateInterval('PT1M'));
                if ($fim > $fimAgora) {
                    $fim = $fimAgora;
                }
                if ($ini >= $fim) {
                    $aviso = '"A partir de" precisa ser anterior a "Até" e ao momento atual. Exibindo o dia de hoje.';
                    $preset = 'hoje';
                    $ini = $hoje;
                    $fim = $fimAgora;
                }
                break;
            default:    $ini = $hoje; $fim = $fimAgora;
        }
        $limite = $fim->sub(new DateInterval('P' . self::MAX_DIAS . 'D'));
        if ($ini < $limite) {
            $ini = $limite;
            $aviso = 'Período limitado a ' . self::MAX_DIAS . ' dias.';
        }

        $seg = $fim->getTimestamp() - $ini->getTimestamp();
        $gran = $seg <= 48 * 3600 ? 'hora' : ($seg <= 92 * 86400 ? 'dia' : 'semana');
        $antFim = $ini;
        $antIni = $ini->sub(new DateInterval('PT' . $seg . 'S'));

        $local = (string) ($in['local'] ?? '');
        $localId = preg_match('/^[0-9a-f-]{36}$/i', $local) ? $local : null;

        return compact('preset', 'ini', 'fim', 'localId', 'gran', 'antIni', 'antFim', 'aviso');
    }

    private static function lerData(string $txt): ?DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $txt);
            if ($d !== false && $d->format($fmt) === $txt) {
                return $d;
            }
        }
        return null;
    }

    /** Texto do período para o cabeçalho (ex.: "10/10/2026 00:00 até agora"). */
    public static function descreverPeriodo(array $f, ?DateTimeImmutable $agora = null): string
    {
        $agora ??= new DateTimeImmutable('now');
        $ateAgora = $f['fim'] > $agora;
        $fimExib = $f['fim']->sub(new DateInterval('PT1M'));
        return $f['ini']->format('d/m/Y H:i') . ' até ' . ($ateAgora ? 'agora' : $fimExib->format('d/m/Y H:i'));
    }

    // -------------------------------------------------------------- consultas

    /** Totais do período: entradas, saídas, pessoas e veículos distintos, externos sem solicitação. */
    public static function resumo(DateTimeImmutable $ini, DateTimeImmutable $fim, ?string $localId): array
    {
        [$w, $p] = self::onde($ini, $fim, $localId);
        $r = Database::one(
            "SELECT COALESCE(SUM(r.sentido = 'entrada'), 0) AS entradas,
                    COALESCE(SUM(r.sentido = 'saida'), 0)   AS saidas,
                    COUNT(DISTINCT CASE WHEN r.sentido = 'entrada' THEN r.pessoa_id END)  AS pessoas,
                    COUNT(DISTINCT CASE WHEN r.sentido = 'entrada' THEN r.veiculo_id END) AS veiculos,
                    COALESCE(SUM(r.sentido = 'entrada' AND p.categoria IN ('visitante','terceiro')), 0) AS entradas_externos,
                    COALESCE(SUM(r.sentido = 'entrada' AND p.categoria IN ('visitante','terceiro') AND r.solicitacao_id IS NULL), 0) AS sem_solicitacao
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
              WHERE {$w}",
            $p
        ) ?? [];
        return array_map('intval', $r);
    }

    /** Permanência média (minutos) de visitantes e terceiros que saíram no período (pares entrada→saída < 24 h). */
    public static function permanenciaMedia(DateTimeImmutable $ini, DateTimeImmutable $fim, ?string $localId): ?int
    {
        $params = [$ini->sub(new DateInterval('P1D'))->format(self::FMT), $fim->format(self::FMT)];
        $local = '';
        if ($localId) {
            $local = ' AND r.local_id = ?';
            $params[] = $localId;
        }
        $params[] = $ini->format(self::FMT);
        $v = Database::value(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, x.entrada_em, x.data_hora))
               FROM (
                 SELECT r.data_hora, r.sentido, p.categoria,
                        LAG(r.sentido)   OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora, r.seq) AS sentido_anterior,
                        LAG(r.data_hora) OVER (PARTITION BY r.pessoa_id, r.local_id ORDER BY r.data_hora, r.seq) AS entrada_em
                   FROM registros_acesso r
                   JOIN pessoas p ON p.id = r.pessoa_id
                  WHERE r.data_hora >= ? AND r.data_hora < ?{$local}
               ) x
              WHERE x.sentido = 'saida' AND x.sentido_anterior = 'entrada' AND x.data_hora >= ?
                AND x.categoria IN ('visitante','terceiro')
                AND TIMESTAMPDIFF(MINUTE, x.entrada_em, x.data_hora) BETWEEN 0 AND 1440",
            $params
        );
        return $v === null ? null : (int) round((float) $v);
    }

    /** Presença agora: total, por categoria e por local (locais ativos, inclusive com zero). */
    public static function presenca(?string $localId): array
    {
        $params = [];
        $w = '';
        if ($localId) {
            $w = ' WHERE local_id = ?';
            $params[] = $localId;
        }
        $linhas = Database::all(
            "SELECT local_id, pessoa_categoria AS categoria, COUNT(*) AS n FROM vw_presenca_dentro{$w} GROUP BY local_id, pessoa_categoria",
            $params
        );
        $locais = Database::all(
            'SELECT id, nome, tipo FROM locais WHERE ativo = 1' . ($localId ? ' AND id = ?' : '') . ' ORDER BY nome',
            $localId ? [$localId] : []
        );
        $porCategoria = array_fill_keys(['colaborador', 'visitante', 'terceiro'], 0);
        $porLocal = [];
        foreach ($locais as $l) {
            $porLocal[$l['id']] = ['nome' => $l['nome'], 'tipo' => $l['tipo'], 'n' => 0];
        }
        $total = 0;
        foreach ($linhas as $r) {
            $n = (int) $r['n'];
            $total += $n;
            $porCategoria[$r['categoria']] = ($porCategoria[$r['categoria']] ?? 0) + $n;
            if (isset($porLocal[$r['local_id']])) {
                $porLocal[$r['local_id']]['n'] += $n;
            }
        }
        return ['total' => $total, 'porCategoria' => $porCategoria, 'porLocal' => array_values($porLocal)];
    }

    /**
     * Entradas por intervalo (hora/dia/semana) e categoria, com todos os intervalos preenchidos.
     * @return array{rotulos: list<string>, dicas: list<string>, series: array<string, list<int>>}
     */
    public static function serieEntradas(array $f): array
    {
        $chave = match ($f['gran']) {
            'hora'   => "DATE_FORMAT(r.data_hora, '%Y-%m-%d %H:00')",
            'semana' => "DATE_FORMAT(DATE_SUB(DATE(r.data_hora), INTERVAL WEEKDAY(r.data_hora) DAY), '%Y-%m-%d')",
            default  => "DATE_FORMAT(r.data_hora, '%Y-%m-%d')",
        };
        [$w, $p] = self::onde($f['ini'], $f['fim'], $f['localId']);
        $linhas = Database::all(
            "SELECT {$chave} AS k, COALESCE(p.categoria, 'veiculo') AS c, COUNT(*) AS n
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
              WHERE r.sentido = 'entrada' AND {$w}
              GROUP BY k, c",
            $p
        );

        // Intervalos contínuos
        $chaves = [];
        $rotulos = [];
        $dicas = [];
        $passo = match ($f['gran']) { 'hora' => 'PT1H', 'semana' => 'P7D', default => 'P1D' };
        $cur = match ($f['gran']) {
            'hora'   => $f['ini']->setTime((int) $f['ini']->format('H'), 0),
            'semana' => $f['ini']->setTime(0, 0)->sub(new DateInterval('P' . ((int) $f['ini']->format('N') - 1) . 'D')),
            default  => $f['ini']->setTime(0, 0),
        };
        $i = 0;
        while ($cur < $f['fim'] && $i++ < 400) {
            $k = $f['gran'] === 'hora' ? $cur->format('Y-m-d H:00') : $cur->format('Y-m-d');
            $chaves[] = $k;
            if ($f['gran'] === 'hora') {
                $rotulos[] = $cur->format('H') . 'h';
                $dicas[] = $cur->format('d/m H:00');
            } elseif ($f['gran'] === 'semana') {
                $rotulos[] = $cur->format('d/m');
                $dicas[] = 'Semana de ' . $cur->format('d/m/Y');
            } else {
                $rotulos[] = $cur->format('d/m');
                $dicas[] = $cur->format('d/m/Y');
            }
            $cur = $cur->add(new DateInterval($passo));
        }
        $pos = array_flip($chaves);
        $series = [];
        foreach (array_keys(self::SERIES) as $s) {
            $series[$s] = array_fill(0, count($chaves), 0);
        }
        foreach ($linhas as $r) {
            if (isset($pos[$r['k']], $series[$r['c']])) {
                $series[$r['c']][$pos[$r['k']]] += (int) $r['n'];
            }
        }
        if (array_sum($series['veiculo']) === 0) {
            unset($series['veiculo']); // série só aparece quando existe
        }
        return ['rotulos' => $rotulos, 'dicas' => $dicas, 'series' => $series];
    }

    /** Entradas por hora do dia (0–23) no período — horário de pico. */
    public static function porHoraDoDia(array $f): array
    {
        [$w, $p] = self::onde($f['ini'], $f['fim'], $f['localId']);
        $v = array_fill(0, 24, 0);
        foreach (Database::all("SELECT HOUR(r.data_hora) AS h, COUNT(*) AS n FROM registros_acesso r WHERE r.sentido = 'entrada' AND {$w} GROUP BY h", $p) as $r) {
            $v[(int) $r['h']] = (int) $r['n'];
        }
        return $v;
    }

    /** Empresas de visitantes e terceiros com mais entradas no período. */
    public static function topEmpresas(array $f, int $limite = 8): array
    {
        [$w, $p] = self::onde($f['ini'], $f['fim'], $f['localId']);
        return Database::all(
            "SELECT COALESCE(NULLIF(TRIM(p.empresa), ''), '(sem empresa)') AS empresa,
                    COUNT(*) AS entradas, COUNT(DISTINCT r.pessoa_id) AS pessoas
               FROM registros_acesso r
               JOIN pessoas p ON p.id = r.pessoa_id
              WHERE r.sentido = 'entrada' AND p.categoria IN ('visitante','terceiro') AND {$w}
              GROUP BY empresa
              ORDER BY entradas DESC, empresa
              LIMIT " . max(1, min(20, $limite)),
            $p
        );
    }

    /** Solicitações recebidas no período por situação + pendências atuais. */
    public static function solicitacoes(array $f): array
    {
        $params = [$f['ini']->format(self::FMT), $f['fim']->format(self::FMT)];
        $local = '';
        if ($f['localId']) {
            $local = ' AND local_id = ?';
            $params[] = $f['localId'];
        }
        $porStatus = array_fill_keys(['aguardando_ssma', 'pendente', 'aprovada', 'realizada', 'recusada', 'expirada'], 0);
        foreach (Database::all(
            "SELECT status, COUNT(*) AS n FROM solicitacoes_acesso
              WHERE aguardando_preenchimento = 0 AND created_at >= ? AND created_at < ?{$local}
              GROUP BY status",
            $params
        ) as $r) {
            $porStatus[$r['status']] = (int) $r['n'];
        }
        $decididas = $porStatus['aprovada'] + $porStatus['realizada'] + $porStatus['recusada'];
        $taxa = $decididas > 0 ? (int) round(100 * ($porStatus['aprovada'] + $porStatus['realizada']) / $decididas) : null;

        $pLocal = $f['localId'] ? [$f['localId']] : [];
        $atual = Database::one(
            "SELECT COALESCE(SUM(status IN ('pendente','aguardando_ssma') AND aguardando_preenchimento = 0), 0) AS pendentes,
                    COALESCE(SUM(status = 'aguardando_ssma' AND aguardando_preenchimento = 0), 0) AS aguardando_ssma,
                    COALESCE(SUM(status = 'aprovada' AND data_prevista = CURDATE()), 0) AS aprovadas_hoje,
                    COALESCE(SUM(aguardando_preenchimento = 1 AND status = 'pendente'), 0) AS links_abertos
               FROM solicitacoes_acesso" . ($f['localId'] ? ' WHERE local_id = ?' : ''),
            $pLocal
        ) ?? [];
        return [
            'porStatus' => $porStatus,
            'total'     => array_sum($porStatus),
            'taxaAprovacao' => $taxa,
            'atual'     => array_map('intval', $atual),
        ];
    }

    /** Verificações SSMA no período: aptos × não aptos. */
    public static function ssma(array $f): array
    {
        $params = [$f['ini']->format(self::FMT), $f['fim']->format(self::FMT)];
        $join = '';
        if ($f['localId']) {
            $join = ' JOIN solicitacoes_acesso s ON s.id = v.solicitacao_id AND s.local_id = ?';
            array_unshift($params, $f['localId']);
        }
        $r = ['apto' => 0, 'nao_apto' => 0];
        foreach (Database::all("SELECT v.status, COUNT(*) AS n FROM verificacoes_ssma v{$join} WHERE v.created_at >= ? AND v.created_at < ? GROUP BY v.status", $params) as $l) {
            $r[$l['status']] = (int) $l['n'];
        }
        return $r;
    }

    /**
     * Situação documental de pessoas ativas por tipo — considera só o documento mais recente
     * de cada pessoa/tipo (um ASO renovado não conta como vencido).
     */
    public static function documentos(): array
    {
        $linhas = Database::all(
            "SELECT d.tipo,
                    SUM(d.v < CURDATE()) AS vencidos,
                    SUM(d.v >= CURDATE() AND d.v <= CURDATE() + INTERVAL 30 DAY) AS a_vencer,
                    SUM(d.v > CURDATE() + INTERVAL 30 DAY) AS validos
               FROM (SELECT x.pessoa_id, x.tipo, MAX(x.validade) AS v FROM documentos x GROUP BY x.pessoa_id, x.tipo) d
               JOIN pessoas p ON p.id = d.pessoa_id AND p.ativo = 1
              GROUP BY d.tipo"
        );
        $porTipo = [];
        $tot = ['vencidos' => 0, 'a_vencer' => 0, 'validos' => 0];
        foreach ($linhas as $l) {
            $porTipo[$l['tipo']] = ['vencidos' => (int) $l['vencidos'], 'a_vencer' => (int) $l['a_vencer'], 'validos' => (int) $l['validos']];
            foreach ($tot as $k => $_) {
                $tot[$k] += (int) $l[$k];
            }
        }
        $ordem = array_flip(array_keys(TIPOS_DOC));
        uksort($porTipo, fn ($a, $b) => ($ordem[$a] ?? 99) <=> ($ordem[$b] ?? 99));
        return ['porTipo' => $porTipo, 'total' => $tot];
    }

    /** Alertas de segurança sobre quem está dentro agora. */
    public static function alertas(?string $localId, int $horasLimite): array
    {
        $pl = $localId ? [$localId] : [];
        $wl = $localId ? ' AND pr.local_id = ?' : '';
        $docs = Database::all(
            "SELECT pr.pessoa_id, pr.pessoa_nome, pr.pessoa_categoria, pr.pessoa_empresa, pr.local_nome, pr.entrada_em,
                    GROUP_CONCAT(dt.tipo ORDER BY dt.tipo SEPARATOR ',') AS tipos
               FROM vw_presenca_dentro pr
               JOIN (SELECT pessoa_id, tipo, MAX(validade) AS v FROM documentos GROUP BY pessoa_id, tipo) dt
                 ON dt.pessoa_id = pr.pessoa_id AND dt.v < CURDATE()
              WHERE 1 = 1{$wl}
              GROUP BY pr.pessoa_id, pr.pessoa_nome, pr.pessoa_categoria, pr.pessoa_empresa, pr.local_nome, pr.entrada_em
              ORDER BY pr.entrada_em
              LIMIT 20",
            $pl
        );
        $longos = Database::all(
            "SELECT pr.pessoa_id, pr.pessoa_nome, pr.pessoa_categoria, pr.pessoa_empresa, pr.local_nome, pr.entrada_em
               FROM vw_presenca_dentro pr
              WHERE pr.entrada_em < (NOW() - INTERVAL ? HOUR){$wl}
              ORDER BY pr.entrada_em
              LIMIT 20",
            [$horasLimite, ...$pl]
        );
        return ['documentos' => $docs, 'permanencia' => $longos, 'horas' => $horasLimite];
    }

    /** Últimos eventos do período. */
    public static function ultimos(array $f, int $limite = 8): array
    {
        [$w, $p] = self::onde($f['ini'], $f['fim'], $f['localId']);
        return Database::all(
            "SELECT r.id, r.sentido, r.data_hora, p.nome AS pessoa_nome, p.categoria, v.placa, l.nome AS local_nome
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
               LEFT JOIN veiculos v ON v.id = r.veiculo_id
               JOIN locais l ON l.id = r.local_id
              WHERE {$w}
              ORDER BY r.data_hora DESC, r.seq DESC
              LIMIT " . max(1, min(50, $limite)),
            $p
        );
    }

    /** Variação percentual (null quando não há base). */
    public static function variacao(int $atual, int $anterior): ?int
    {
        if ($anterior <= 0) {
            return null;
        }
        return (int) round(100 * ($atual - $anterior) / $anterior);
    }

    /** @return array{0: string, 1: list<string>} cláusula WHERE do período/local em registros_acesso r */
    private static function onde(DateTimeImmutable $ini, DateTimeImmutable $fim, ?string $localId): array
    {
        $w = 'r.data_hora >= ? AND r.data_hora < ?';
        $p = [$ini->format(self::FMT), $fim->format(self::FMT)];
        if ($localId) {
            $w .= ' AND r.local_id = ?';
            $p[] = $localId;
        }
        return [$w, $p];
    }
}
