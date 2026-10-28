<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Core\{Audit, Database, Uuid};

/** Regras puras de SLA e atendimento, no fuso configurado na aplicação. */
final class Chamados
{
    public const STATUS = [
        'novo' => 'Novo', 'atribuido' => 'Em atendimento (atribuído)',
        'planejado' => 'Em atendimento (planejado)', 'pendente' => 'Pendente',
        'solucionado' => 'Solucionado', 'fechado' => 'Fechado',
    ];
    public const URGENCIAS = [1 => 'Muito alta', 2 => 'Alta', 3 => 'Média', 4 => 'Baixa', 5 => 'Muito baixa'];
    public const EXPLICACOES = [
        1 => 'Atendimento imediato: operação parada ou risco iminente.',
        2 => 'Atendimento urgente: atividade importante comprometida.',
        3 => 'Atendimento normal: dificuldade com alternativa temporária.',
        4 => 'Pode aguardar: impacto pequeno na rotina.',
        5 => 'Pode ser planejado: melhoria ou orientação sem interrupção.',
    ];
    public const IMPACTOS = [
        1 => 'A — Não afeta a operação', 2 => 'B — Afeta parcialmente uma atividade ou usuário',
        3 => 'C — Afeta um setor ou grupo', 4 => 'D — Processo crítico, parada ou risco',
    ];
    public const PRIORIDADES = [1 => 'Crítica', 2 => 'Muito alta', 3 => 'Alta', 4 => 'Média', 5 => 'Baixa', 6 => 'Muito baixa'];
    public const PRAZOS = ['no_prazo' => 'No prazo', 'vencendo' => 'Vencendo', 'vencido' => 'Vencido'];
    public const TIPOS = ['comentario' => 'Comentário público', 'interno' => 'Nota interna', 'solucao' => 'Solução', 'status' => 'Status', 'atribuicao' => 'Atribuição'];

    /**
     * Matriz inspirada no GLPI, com seis níveis locais (1 = mais crítico).
     * Urgência: 1 muito alta ... 5 muito baixa; impacto: A=1 ... D=4.
     *                A  B  C  D
     * Muito alta     3  2  1  1
     * Alta           4  3  2  1
     * Média          5  4  3  2
     * Baixa          6  5  4  3
     * Muito baixa    6  6  5  4
     */
    public static function prioridade(int $urgencia, int $impacto): int
    {
        if (!isset(self::URGENCIAS[$urgencia], self::IMPACTOS[$impacto])) {
            throw new InvalidArgumentException(t('Urgência ou impacto fora da faixa.'));
        }
        return [[3, 2, 1, 1], [4, 3, 2, 1], [5, 4, 3, 2], [6, 5, 4, 3], [6, 6, 5, 4]][$urgencia - 1][$impacto - 1];
    }

    public static function prazo(DateTimeImmutable $inicio, ?int $minutos, ?array $calendario = null): ?DateTimeImmutable
    {
        if ($minutos === null) { return null; }
        if ($minutos <= 0 || $minutos > 5256000) {
            throw new InvalidArgumentException(t('SLA inválido.'));
        }
        return self::somarSegundos($inicio, $minutos * 60, $calendario);
    }

    /** Calendário diurno; não aceita expediente vazio, noturno nem datas impossíveis. */
    public static function calendario(array $dados): array
    {
        $dias = $dados['dias'] ?? [];
        if (is_string($dias)) { $dias = explode(',', $dias); }
        if (!is_array($dias) || !$dias) { throw new InvalidArgumentException(t('Informe ao menos um dia útil.')); }
        foreach ($dias as $dia) {
            if (!is_scalar($dia) || !preg_match('/^[1-7]$/D', (string) $dia)) {
                throw new InvalidArgumentException(t('Dias úteis inválidos.'));
            }
        }
        $dias = array_values(array_unique(array_map('intval', $dias)));
        sort($dias);
        $inicio = $dados['inicio'] ?? '';
        $fim = $dados['fim'] ?? '';
        if (!is_string($inicio) || !is_string($fim) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $inicio)
            || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $fim) || $inicio >= $fim) {
            throw new InvalidArgumentException(t('Informe um expediente válido, com início antes do fim.'));
        }
        $feriados = $dados['feriados'] ?? [];
        if (is_string($feriados)) { $feriados = preg_split('/[\s,;]+/', trim($feriados), -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($feriados) || count($feriados) > 3660) { throw new InvalidArgumentException(t('Feriados inválidos; use datas no formato AAAA-MM-DD.')); }
        foreach ($feriados as $data) {
            if (!is_string($data) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException(t('Feriados inválidos; use datas no formato AAAA-MM-DD.'));
            }
        }
        $feriados = array_values(array_unique($feriados));
        sort($feriados);
        return compact('dias', 'inicio', 'fim', 'feriados');
    }

    public static function calendarioConfigurado(array $categoria): ?array
    {
        if (empty($categoria['usar_horario_comercial']) || !Settings::bool('horario_comercial_ativo')) { return null; }
        return self::calendario([
            'dias' => Settings::get('horario_dias', '1,2,3,4,5'),
            'inicio' => Settings::get('horario_inicio', '07:00'), 'fim' => Settings::get('horario_fim', '17:00'),
            'feriados' => Settings::get('feriados', ''),
        ]);
    }

    public static function calendarioChamado(array $chamado): ?array
    {
        $c = $chamado['calendario_sla'] ?? null;
        return is_string($c) ? json_decode($c, true, 512, JSON_THROW_ON_ERROR) : $c;
    }

    private static function diaUtil(DateTimeImmutable $dia, array $c): bool
    {
        return in_array((int) $dia->format('N'), $c['dias'], true) && !in_array($dia->format('Y-m-d'), $c['feriados'], true);
    }

    /** Segundos efetivamente consumidos; também usado na pausa e nos selos de 20%. */
    public static function segundosUteis(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?array $calendario = null): int
    {
        if ($fim < $inicio) { return -self::segundosUteis($fim, $inicio, $calendario); }
        if ($calendario === null) { return $fim->getTimestamp() - $inicio->getTimestamp(); }
        $c = self::calendario($calendario);
        $total = 0;
        for ($dia = $inicio->setTime(0, 0); $dia < $fim; $dia = $dia->modify('+1 day')) {
            if (!self::diaUtil($dia, $c)) { continue; }
            $abertura = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $c['inicio'], $inicio->getTimezone());
            $fechamento = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $c['fim'], $inicio->getTimezone());
            $total += max(0, min($fim->getTimestamp(), $fechamento->getTimestamp()) - max($inicio->getTimestamp(), $abertura->getTimestamp()));
        }
        return $total;
    }

    private static function somarSegundos(DateTimeImmutable $inicio, int $segundos, ?array $calendario): DateTimeImmutable
    {
        if ($segundos === 0) { return $inicio; }
        if ($calendario === null) { return $inicio->modify('+' . $segundos . ' seconds'); }
        $c = self::calendario($calendario);
        $cursor = $inicio;
        while (true) {
            if (self::diaUtil($cursor, $c)) {
                $abertura = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $c['inicio'], $inicio->getTimezone());
                $fim = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $c['fim'], $inicio->getTimezone());
                $cursor = max($cursor, $abertura);
                $disponivel = max(0, $fim->getTimestamp() - $cursor->getTimestamp());
                if ($segundos <= $disponivel) { return $cursor->modify('+' . $segundos . ' seconds'); }
                $segundos -= $disponivel;
            }
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }
    }

    /** O prazo persistido é a base; a pausa é somada apenas na leitura, nunca duas vezes. */
    public static function prazoSolucao(array $chamado, DateTimeImmutable $agora): ?DateTimeImmutable
    {
        if (empty($chamado['prazo_solucao'])) { return null; }
        $segundos = max(0, (int) ($chamado['segundos_pendente'] ?? 0));
        $calendario = self::calendarioChamado($chamado);
        if (($chamado['status'] ?? '') === 'pendente' && !empty($chamado['pendente_desde'])) {
            $segundos += max(0, self::segundosUteis(new DateTimeImmutable($chamado['pendente_desde']), $agora, $calendario));
        }
        return self::somarSegundos(new DateTimeImmutable($chamado['prazo_solucao']), $segundos, $calendario);
    }

    public static function transicoes(string $status): array
    {
        return match ($status) {
            'novo' => ['atribuido', 'planejado', 'pendente', 'solucionado'],
            'atribuido' => ['planejado', 'pendente', 'solucionado'],
            'planejado' => ['atribuido', 'pendente', 'solucionado'],
            'pendente' => ['atribuido', 'planejado', 'solucionado'],
            'solucionado' => ['fechado', 'atribuido'],
            default => [],
        };
    }

    public static function podeTransicionar(string $origem, string $destino): bool
    {
        return in_array($destino, self::transicoes($origem), true);
    }

    /** Alterações determinísticas, aplicadas pelo controller com SELECT FOR UPDATE. */
    public static function mudarStatus(array $chamado, string $destino, DateTimeImmutable $agora): array
    {
        if (!self::podeTransicionar($chamado['status'], $destino)) {
            throw new InvalidArgumentException(t('Transição de status não permitida.'));
        }
        $mudancas = ['status' => $destino];
        if ($chamado['status'] === 'pendente') {
            $inicio = new DateTimeImmutable($chamado['pendente_desde']);
            $mudancas['segundos_pendente'] = (int) $chamado['segundos_pendente'] + max(0, self::segundosUteis($inicio, $agora, self::calendarioChamado($chamado)));
            $mudancas['pendente_desde'] = null;
        }
        if ($destino === 'pendente') { $mudancas['pendente_desde'] = $agora->format('Y-m-d H:i:s'); }
        if ($destino === 'solucionado') { $mudancas['solucionado_em'] = $agora->format('Y-m-d H:i:s'); }
        if ($destino === 'fechado') { $mudancas['fechado_em'] = $agora->format('Y-m-d H:i:s'); }
        if ($chamado['status'] === 'solucionado' && $destino === 'atribuido') {
            $mudancas['solucionado_em'] = null;
            $mudancas['fechado_em'] = null;
        }
        return $mudancas;
    }

    public static function situacaoPrazo(DateTimeImmutable $inicio, ?DateTimeImmutable $prazo, DateTimeImmutable $agora, ?array $calendario = null): string
    {
        if ($prazo === null) { return 'no_prazo'; }
        $restante = self::segundosUteis($agora, $prazo, $calendario);
        if ($restante < 0) { return 'vencido'; }
        $total = max(0, self::segundosUteis($inicio, $prazo, $calendario));
        return $restante <= $total * 0.2 ? 'vencendo' : 'no_prazo';
    }

    /** Mede o orçamento original do SLA; a pausa não aumenta a janela de 20%. */
    public static function situacaoSolucao(array $chamado, DateTimeImmutable $agora): string
    {
        if (empty($chamado['prazo_solucao'])) { return 'no_prazo'; }
        $fim = new DateTimeImmutable($chamado['solucionado_em'] ?? $chamado['fechado_em'] ?? $agora->format('Y-m-d H:i:s'));
        $calendario = self::calendarioChamado($chamado);
        $total = max(0, self::segundosUteis(new DateTimeImmutable($chamado['created_at']), new DateTimeImmutable($chamado['prazo_solucao']), $calendario));
        // Subtrai as pausas do consumo, inclusive quando o chamado já estava vencido.
        $pausa = max(0, (int) ($chamado['segundos_pendente'] ?? 0));
        if ($chamado['status'] === 'pendente' && !empty($chamado['pendente_desde'])) {
            $pausa += max(0, self::segundosUteis(new DateTimeImmutable($chamado['pendente_desde']), $fim, $calendario));
        }
        $restante = $total - self::segundosUteis(new DateTimeImmutable($chamado['created_at']), $fim, $calendario) + $pausa;
        return $restante < 0 ? 'vencido' : ($restante <= $total * 0.2 ? 'vencendo' : 'no_prazo');
    }

    public static function podeFecharAutomaticamente(array $chamado, DateTimeImmutable $agora, int $dias = 7): bool
    {
        return $dias > 0 && $chamado['status'] === 'solucionado' && !empty($chamado['solucionado_em'])
            && (new DateTimeImmutable($chamado['solucionado_em']))->modify('+' . $dias . ' days') <= $agora;
    }

    /**
     * Entrada para o agendador do gerente: não executa em GET nem no carregamento do menu.
     * Configuração zero desliga o fechamento. Revalida sob lock para não fechar uma reabertura concorrente.
     */
    public static function fecharAutomaticamente(int $limite = 100): int
    {
        $dias = Settings::int('chamados_fechar_dias', 7);
        if (!Settings::bool('chamados_ativo') || $dias <= 0) { return 0; }
        $agora = new DateTimeImmutable();
        $corte = $agora->modify('-' . $dias . ' days')->format('Y-m-d H:i:s');
        $candidatos = Database::all("SELECT id FROM chamados WHERE status = 'solucionado' AND solucionado_em <= ? ORDER BY solucionado_em LIMIT " . max(1, min(1000, $limite)), [$corte]);
        $total = 0;
        foreach ($candidatos as $item) {
            $total += Database::transaction(static function () use ($item, $agora, $dias): int {
                $c = Database::one('SELECT * FROM chamados WHERE id = ? FOR UPDATE', [$item['id']]);
                if (!$c || !self::podeFecharAutomaticamente($c, $agora, $dias)) { return 0; }
                $mudancas = self::mudarStatus($c, 'fechado', $agora) + ['fechado_automaticamente' => 1];
                Database::update('chamados', $mudancas, 'id = ?', [$c['id']]);
                Database::insert('chamado_acompanhamentos', [
                    'id' => Uuid::v4(), 'chamado_id' => $c['id'], 'usuario_id' => null,
                    'tipo' => 'status', 'texto' => 'fechado',
                ]);
                Audit::log('chamados', $c['id'], 'fechar', ['automatico' => true, 'dias' => $dias]);
                Notificacoes::chamado('fechado', array_replace($c, $mudancas), ['usuario_id' => null, 'automatico' => true]);
                return 1;
            });
        }
        return $total;
    }

    /** Uma única avaliação, exclusiva do solicitante, após fechamento e antes da retenção. */
    public static function podeAvaliar(array $chamado, string $usuarioId, bool $jaAvaliou): bool
    {
        return $chamado['status'] === 'fechado' && $chamado['solicitante_id'] === $usuarioId
            && empty($chamado['anonimizado_em']) && !$jaAvaliou;
    }

    public static function validarSatisfacao(mixed $nota, string $comentario): array
    {
        $n = is_int($nota) || is_string($nota) ? filter_var($nota, FILTER_VALIDATE_INT) : false;
        if ($n === false || $n < 1 || $n > 5 || mb_strlen($comentario) > 2000) {
            throw new InvalidArgumentException(t('Escolha uma nota de 1 a 5 e um comentário de até 2.000 caracteres.'));
        }
        return ['nota' => $n, 'comentario' => trim($comentario) === '' ? null : trim($comentario)];
    }

    public static function corStatus(string $status): string
    {
        return match ($status) { 'novo', 'pendente' => 'aviso', 'atribuido', 'planejado' => 'info', 'solucionado' => 'sucesso', default => 'neutro' };
    }

    public static function corPrioridade(int $prioridade): string
    {
        return match ($prioridade) { 1, 2 => 'perigo', 3 => 'aviso', 4 => 'info', default => 'neutro' };
    }

    /** Caminhos completos para select sem JS; evita loops em cadastros inconsistentes. */
    public static function categoriasEmArvore(array $categorias): array
    {
        $porId = array_column($categorias, null, 'id');
        foreach ($categorias as &$categoria) {
            $nomes = [$categoria['nome']];
            $visitados = [$categoria['id'] => true];
            $pai = $categoria['parent_id'];
            while ($pai !== null && isset($porId[$pai]) && !isset($visitados[$pai])) {
                $visitados[$pai] = true;
                array_unshift($nomes, $porId[$pai]['nome']);
                $pai = $porId[$pai]['parent_id'];
            }
            $categoria['caminho'] = implode(' › ', $nomes);
        }
        unset($categoria);
        usort($categorias, static fn ($a, $b) => strnatcasecmp($a['caminho'], $b['caminho']));
        return $categorias;
    }
}
