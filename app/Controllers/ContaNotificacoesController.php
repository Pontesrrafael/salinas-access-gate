<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Services\Email;
use App\Services\PreferenciasNotificacao;
use Throwable;

/**
 * Minha conta → Notificações por e-mail (/conta/notificacoes).
 * Permite a cada usuário escolher em quais eventos de chamados deseja receber avisos por e-mail.
 */
final class ContaNotificacoesController extends Controller
{
    public function index(): void
    {
        $u = Auth::requireLogin();
        $atende = $this->ehAtendente($u);
        $preferencias = PreferenciasNotificacao::todas((string) $u['id']);

        $this->view('conta/notificacoes', [
            'titulo'             => t('Notificações por e-mail'),
            'descricao'          => t('Escolha em quais ocasiões você deseja receber avisos dos chamados'),
            'usuario'            => $u,
            'emailUsuario'       => trim((string) ($u['email'] ?? '')),
            'origemConta'        => (string) ($u['auth_origem'] ?? 'local'),
            'emailAtivo'         => Email::estaAtivo(),
            'atende'             => $atende,
            'preferencias'       => $preferencias,
            'eventosSolicitante' => PreferenciasNotificacao::EVENTOS_SOLICITANTE,
            'eventosAtendente'   => PreferenciasNotificacao::EVENTOS_ATENDENTE,
        ]);
    }

    public function salvar(): void
    {
        $u = Auth::requireLogin();
        $uid = (string) $u['id'];
        $atende = $this->ehAtendente($u);
        $acao = Http::str('acao', 'salvar');

        if ($acao === 'desligar_todos') {
            $persistido = PreferenciasNotificacao::desligarTodos($uid, $atende);
            Audit::log('usuario_notificacoes', $uid, 'DESLIGAR_TODOS', [
                'atende'  => $atende,
                'eventos' => $persistido,
            ]);
            $this->ok(t('Todas as notificações por e-mail foram desligadas.'), '/conta/notificacoes');
        }

        if ($acao === 'restaurar_padrao') {
            PreferenciasNotificacao::restaurarPadrao($uid);
            Audit::log('usuario_notificacoes', $uid, 'RESTAURAR_PADRAO', [
                'atende' => $atende,
            ]);
            $this->ok(t('Preferências restauradas para o padrão (todas ligadas).'), '/conta/notificacoes');
        }

        $marcados = $_POST['eventos'] ?? [];
        if (!is_array($marcados)) {
            $marcados = [];
        }

        $persistido = PreferenciasNotificacao::salvar($uid, $marcados, $atende);
        Audit::log('usuario_notificacoes', $uid, 'UPDATE', [
            'atende'  => $atende,
            'eventos' => $persistido,
        ]);

        $this->ok(t('Preferências de notificação salvas.'), '/conta/notificacoes');
    }

    private function ehAtendente(array $u): bool
    {
        if (($u['papel'] ?? '') === 'admin') {
            return true;
        }
        try {
            if (Auth::id() === ($u['id'] ?? null)) {
                return Auth::can('chamados.atender');
            }
            return (bool) Database::value(
                'SELECT 1 FROM servico_area_membros WHERE usuario_id = ? LIMIT 1',
                [(string) ($u['id'] ?? '')]
            );
        } catch (Throwable) {
            return false;
        }
    }
}
