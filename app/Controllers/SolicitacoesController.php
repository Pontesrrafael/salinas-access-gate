<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Core\Uuid;
use App\Services\Settings;

/**
 * Solicitações de acesso de visitantes/terceiros.
 * Autorizam: Admin/TI, usuários com Segurança Patrimonial ou o responsável da área.
 * Porteiro não autoriza.
 */
final class SolicitacoesController extends Controller
{
    public function index(): void
    {
        $u = Auth::authorize('solicitacoes.ver');
        $aba = Http::str('aba', 'pendentes');
        $q = Http::str('q');

        $where = ['1 = 1'];
        $params = [];
        if ($aba === 'pendentes') {
            $where[] = "s.status IN ('pendente','aguardando_ssma')";
        } elseif ($aba === 'minhas') {
            $where[] = 's.criado_por = ?';
            $params[] = $u['id'];
        } elseif ($aba === 'hoje') {
            $where[] = 's.data_prevista = CURDATE()';
        } else {
            $aba = 'todas';
        }
        if ($q !== '') {
            $where[] = '(s.visitante_nome LIKE ? OR s.empresa LIKE ? OR s.visitante_doc LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $lista = Database::all(
            'SELECT s.*, a.nome AS area_nome, a.responsavel_user_id, l.nome AS local_nome,
                    ua.nome AS autorizador_nome, uc.nome AS criador_nome, p.nome AS pessoa_nome
               FROM solicitacoes_acesso s
               LEFT JOIN areas a ON a.id = s.area_id
               LEFT JOIN locais l ON l.id = s.local_id
               LEFT JOIN usuarios ua ON ua.id = s.autorizado_por
               LEFT JOIN usuarios uc ON uc.id = s.criado_por
               LEFT JOIN pessoas p ON p.id = s.pessoa_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.created_at DESC LIMIT 300',
            $params
        );
        foreach ($lista as &$s) {
            $s['pode_autorizar'] = $this->podeAutorizar($s, $u);
            $s['pode_link'] = $s['token_externo'] !== null && ($s['pode_autorizar'] || $s['criado_por'] === $u['id']);
        }
        unset($s);

        $this->view('solicitacoes/index', [
            'titulo'     => 'Solicitações de Acesso',
            'descricao'  => 'Pedidos de entrada de visitantes e terceiros — autorização por responsável da área, Seg. Patrimonial ou Admin/TI',
            'lista'      => $lista,
            'aba'        => $aba,
            'q'          => $q,
            'areas'      => Database::all('SELECT id, nome FROM areas WHERE ativo = 1 ORDER BY nome'),
            'locais'     => Database::all('SELECT id, nome FROM locais WHERE ativo = 1 ORDER BY nome'),
            'linkGerado' => Session::get('link_gerado'),
            'validadeLinkHoras' => Settings::int('link_externo_validade_horas', 72),
        ]);
        Session::forget('link_gerado');
    }

    public function criar(): void
    {
        $u = Auth::authorize('solicitacoes.criar');
        $nome = Http::nstr('visitante_nome', 200);
        $areaId = Http::str('area_id');
        $data = Http::str('data_prevista');
        $tipo = Http::str('tipo', 'visitante');
        $localId = Http::str('local_id');
        $email = Http::nstr('contato_email', 190);

        if ($nome === null) {
            $this->falha('Nome do visitante/terceiro é obrigatório.', '/solicitacoes');
        }
        if (!Uuid::valid($areaId) || !Database::value('SELECT 1 FROM areas WHERE id = ? AND ativo = 1', [$areaId])) {
            $this->falha('Área responsável é obrigatória.', '/solicitacoes');
        }
        if (!$this->dataValida($data) || $data < date('Y-m-d')) {
            $this->falha('Informe uma data prevista válida (hoje ou futura).', '/solicitacoes');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->falha('E-mail de contato inválido.', '/solicitacoes');
        }
        $id = Uuid::v4();
        $risco = Http::bool('atividade_risco') ? 1 : 0;
        Database::insert('solicitacoes_acesso', [
            'id'               => $id,
            'visitante_nome'   => $nome,
            'visitante_doc'    => Http::nstr('visitante_doc', 30),
            'empresa'          => Http::nstr('empresa', 150),
            'contato_telefone' => Http::nstr('contato_telefone', 30),
            'contato_email'    => $email,
            'tipo'             => $tipo === 'terceiro' ? 'terceiro' : 'visitante',
            'area_id'          => $areaId,
            'local_id'         => Uuid::valid($localId) ? $localId : null,
            'data_prevista'    => $data,
            'motivo'           => Http::nstr('motivo', 2000),
            'atividade_risco'  => $risco,
            'status'           => 'pendente',
            'origem'           => 'interna',
            'criado_por'       => $u['id'],
            'consentimento_lgpd' => 1,
        ]);
        Audit::log('solicitacoes_acesso', $id, 'INSERT', ['visitante_nome' => $nome, 'area_id' => $areaId, 'data_prevista' => $data, 'atividade_risco' => $risco]);
        $this->ok($risco ? 'Solicitação criada e enviada para verificação do SSMA.' : 'Solicitação criada. Aguardando autorização.', '/solicitacoes');
    }

    public function gerarLink(): void
    {
        $u = Auth::authorize('solicitacoes.criar');
        $areaId = Http::str('area_id');
        $data = Http::str('data_prevista');
        $localId = Http::str('local_id');
        if (!Uuid::valid($areaId) || !Database::value('SELECT 1 FROM areas WHERE id = ? AND ativo = 1', [$areaId])) {
            $this->falha('Selecione a área responsável para gerar o link.', '/solicitacoes');
        }
        if (!$this->dataValida($data) || $data < date('Y-m-d')) {
            $this->falha('Informe a data prevista da visita para gerar o link.', '/solicitacoes');
        }
        $horas = max(1, Settings::int('link_externo_validade_horas', 72));
        $token = bin2hex(random_bytes(24));
        $expira = date('Y-m-d H:i:s', time() + $horas * 3600);
        $id = Uuid::v4();
        Database::insert('solicitacoes_acesso', [
            'id'                       => $id,
            'visitante_nome'           => '(aguardando preenchimento externo)',
            'tipo'                     => 'visitante',
            'area_id'                  => $areaId,
            'local_id'                 => Uuid::valid($localId) ? $localId : null,
            'data_prevista'            => $data,
            'status'                   => 'pendente',
            'origem'                   => 'link',
            'token_externo'            => $token,
            'link_expira_em'           => $expira,
            'aguardando_preenchimento' => 1,
            'criado_por'               => $u['id'],
        ]);
        Audit::log('solicitacoes_acesso', $id, 'GERAR_LINK', ['area_id' => $areaId, 'expira_em' => $expira]);
        Session::set('link_gerado', ['url' => Http::absoluteUrl('/solicitar-acesso/' . $token), 'expira' => $expira]);
        $this->ok('Link externo gerado.', '/solicitacoes');
    }

    public function aprovar(string $id): void
    {
        $u = Auth::authorize('solicitacoes.ver');
        $s = $this->carregar($id);
        if (!$this->podeAutorizar($s, $u)) {
            Http::abort(403, 'Somente o responsável da área, Segurança Patrimonial ou Admin/TI podem autorizar.');
        }
        if ((int) $s['aguardando_preenchimento'] === 1) {
            $this->falha('O visitante ainda não preencheu o formulário do link externo.', '/solicitacoes');
        }
        if ($s['status'] !== 'pendente') {
            $msg = $s['status'] === 'aguardando_ssma'
                ? 'Esta solicitação aguarda a verificação do SSMA antes da autorização.'
                : 'Somente solicitações pendentes podem ser aprovadas.';
            $this->falha($msg, '/solicitacoes');
        }
        if ($s['data_prevista'] < date('Y-m-d')) {
            $this->falha('A data prevista já passou. Crie uma nova solicitação.', '/solicitacoes');
        }
        Database::update('solicitacoes_acesso', [
            'status' => 'aprovada', 'motivo_recusa' => null,
            'autorizado_por' => $u['id'], 'autorizado_em' => date('Y-m-d H:i:s'),
        ], "id = ? AND status = 'pendente'", [$id]);
        Audit::log('solicitacoes_acesso', $id, 'aprovada', ['area_id' => $s['area_id'], 'visitante_nome' => $s['visitante_nome']]);
        $this->ok('Solicitação aprovada.', '/solicitacoes', ['aba' => Http::str('aba', 'pendentes')]);
    }

    public function recusar(string $id): void
    {
        $u = Auth::authorize('solicitacoes.ver');
        $s = $this->carregar($id);
        if (!$this->podeAutorizar($s, $u)) {
            Http::abort(403, 'Somente o responsável da área, Segurança Patrimonial ou Admin/TI podem recusar.');
        }
        $motivo = Http::nstr('motivo_recusa', 1000);
        if ($motivo === null || mb_strlen($motivo) < 5) {
            $this->falha('Informe o motivo da recusa (mínimo 5 caracteres).', '/solicitacoes');
        }
        if (!in_array($s['status'], ['pendente', 'aguardando_ssma'], true)) {
            $this->falha('Esta solicitação já foi finalizada.', '/solicitacoes');
        }
        Database::update('solicitacoes_acesso', [
            'status' => 'recusada', 'motivo_recusa' => $motivo,
            'autorizado_por' => $u['id'], 'autorizado_em' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        Audit::log('solicitacoes_acesso', $id, 'recusada', ['area_id' => $s['area_id'], 'visitante_nome' => $s['visitante_nome'], 'motivo_recusa' => $motivo]);
        $this->ok('Solicitação recusada.', '/solicitacoes', ['aba' => Http::str('aba', 'pendentes')]);
    }

    public function copiarLink(string $id): void
    {
        $u = Auth::authorize('solicitacoes.ver');
        $s = $this->carregar($id);
        if ($s['token_externo'] === null || !($this->podeAutorizar($s, $u) || $s['criado_por'] === $u['id'])) {
            Http::json(['erro' => 'Sem permissão para copiar o link'], 403);
        }
        Http::json(['url' => Http::absoluteUrl('/solicitar-acesso/' . $s['token_externo'])]);
    }

    /* ------------------------------------------------------------------ */
    private function carregar(string $id): array
    {
        $this->uuidOu404($id);
        return Database::one(
            'SELECT s.*, a.responsavel_user_id FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id WHERE s.id = ?',
            [$id]
        ) ?? Http::abort(404, 'Solicitação não encontrada.');
    }

    private function podeAutorizar(array $s, array $u): bool
    {
        return $u['papel'] === 'admin'
            || (int) $u['seguranca_patrimonial'] === 1
            || ($s['responsavel_user_id'] !== null && $s['responsavel_user_id'] === $u['id']);
    }

    private function dataValida(string $d): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
