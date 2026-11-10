<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use Throwable;

/**
 * Situação das rotinas agendadas (cron): backup, retenção LGPD, fechamento de chamados, expiração e fila de e-mails.
 * O agendador registra cada execução por `php bin/console.php rotina-registrar <nome> ok|falha`; o painel do Admin/TI avisa
 * quando a última execução falhou ou quando uma rotina diária está há mais de 48 h sem sucesso.
 */
final class Rotinas
{
    /** nome => [rótulo, é diária (alerta se ficar sem sucesso por 48 h)] */
    public const ROTINAS = [
        'backup'               => ['Backup cifrado', true],
        'aplicar-retencao'     => ['Retenção LGPD', true],
        'fechar-chamados'      => ['Fechamento automático de chamados', true],
        'expirar-solicitacoes' => ['Expiração de solicitações', true],
        'enviar-emails'        => ['Envio de e-mails', false],
    ];
    private const ATRASO_SEGUNDOS = 172800;

    public static function registrar(string $nome, string $status): void
    {
        if (!isset(self::ROTINAS[$nome]) || !in_array($status, ['ok', 'falha'], true)) {
            throw new \InvalidArgumentException('Uso: rotina-registrar <' . implode('|', array_keys(self::ROTINAS)) . '> <ok|falha>');
        }
        $atual = self::estado($nome);
        $agora = date('Y-m-d H:i:s');
        Settings::set('rotina_' . $nome, json_encode([
            'status'    => $status,
            'em'        => $agora,
            'ultimo_ok' => $status === 'ok' ? $agora : ($atual['ultimo_ok'] ?? null),
        ], JSON_UNESCAPED_UNICODE), null);
    }

    /** @return array{status?: string, em?: string, ultimo_ok?: ?string} */
    public static function estado(string $nome): array
    {
        $v = json_decode((string) Settings::get('rotina_' . $nome, ''), true);
        return is_array($v) ? $v : [];
    }

    /**
     * Rotinas que precisam de atenção. Rotina nunca registrada não gera alerta (instalação antiga sem o agendador novo).
     * @return list<array{nome: string, rotulo: string, motivo: string, em: string}>
     */
    public static function pendencias(?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable();
        $lista = [];
        try {
            foreach (self::ROTINAS as $nome => [$rotulo, $diaria]) {
                $e = self::estado($nome);
                if (!$e) { continue; }
                if (($e['status'] ?? '') === 'falha') {
                    $lista[] = ['nome' => $nome, 'rotulo' => $rotulo, 'motivo' => 'falha', 'em' => (string) ($e['em'] ?? '')];
                } elseif ($diaria && !empty($e['ultimo_ok']) && $agora->getTimestamp() - strtotime((string) $e['ultimo_ok']) > self::ATRASO_SEGUNDOS) {
                    $lista[] = ['nome' => $nome, 'rotulo' => $rotulo, 'motivo' => 'atrasada', 'em' => (string) $e['ultimo_ok']];
                }
            }
        } catch (Throwable) {
            return [];   // o painel nunca quebra por causa do aviso
        }
        return $lista;
    }
}
