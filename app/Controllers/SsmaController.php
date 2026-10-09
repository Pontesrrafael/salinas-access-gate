<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;

/**
 * Verificação SSMA de terceiros em atividade de risco.
 * Histórico imutável (verificacoes_ssma é protegida por trigger).
 *   Apto     → solicitação volta para "pendente" (segue para autorização da área)
 *   Não apto → solicitação "recusada"
 */
final class SsmaController extends Controller
{
    public const CHECKLIST = [
        'aso'           => 'ASO válido',
        'identificacao' => 'Documento de identificação',
        'autorizacao'   => 'Autorização de acesso',
    ];

    public function index(): void
    {
        Auth::authorize('ssma.verificar');
        $fila = Database::all(
            "SELECT s.id, s.visitante_nome, s.visitante_doc, s.empresa, s.data_prevista, s.motivo, a.nome AS area_nome
               FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id
              WHERE s.status = 'aguardando_ssma' AND s.atividade_risco = 1
              ORDER BY s.data_prevista, s.created_at"
        );
        $historico = Database::all(
            'SELECT v.id, v.status, v.observacao, v.validade_ate, v.created_at, v.verificado_por_nome,
                    s.visitante_nome, s.empresa
               FROM verificacoes_ssma v JOIN solicitacoes_acesso s ON s.id = v.solicitacao_id
              ORDER BY v.created_at DESC LIMIT 30'
        );
        $this->view('ssma/index', [
            'titulo'    => 'Verificação SSMA',
            'descricao' => 'Fila de terceiros em atividade de risco aguardando aptidão',
            'fila'      => $fila,
            'historico' => $historico,
        ]);
    }

    public function form(string $id): void
    {
        Auth::authorize('ssma.verificar');
        $s = $this->carregar($id);
        // Documentos: da pessoa vinculada ou, se não houver, de pessoa com o mesmo CPF informado
        $pessoa = null;
        if ($s['pessoa_id']) {
            $pessoa = Database::one('SELECT id, nome FROM pessoas WHERE id = ?', [$s['pessoa_id']]);
        } elseif (Crypto::cpfValido((string) $s['visitante_doc'])) {
            $pessoa = Database::one('SELECT id, nome FROM pessoas WHERE cpf_hash = ?', [Crypto::cpfHash($s['visitante_doc'])]);
        }
        $docs = $pessoa ? Database::all('SELECT tipo, numero, validade FROM documentos WHERE pessoa_id = ? ORDER BY validade', [$pessoa['id']]) : [];
        $this->view('ssma/form', [
            'titulo'    => 'Verificação SSMA — ' . $s['visitante_nome'],
            's'         => $s,
            'pessoa'    => $pessoa,
            'docs'      => $docs,
            'checklist' => self::CHECKLIST,
        ]);
    }

    public function registrar(string $id): void
    {
        $u = Auth::authorize('ssma.verificar');
        $s = $this->carregar($id);
        if ($s['status'] !== 'aguardando_ssma') {
            $this->falha('Esta solicitação não está mais aguardando o SSMA.', '/ssma');
        }
        $decisao = Http::str('decisao');
        $obs = Http::nstr('observacao', 2000);
        $marcados = (array) ($_POST['checklist'] ?? []);
        $checklist = [];
        foreach (array_keys(self::CHECKLIST) as $k) {
            $checklist[$k] = in_array($k, $marcados, true);
        }
        if (!in_array($decisao, ['apto', 'nao_apto'], true)) {
            $this->falha('Decisão inválida.', '/ssma/' . $id);
        }
        if ($decisao === 'apto' && in_array(false, $checklist, true)) {
            $this->falha('Marque todos os itens do checklist para liberar como Apto.', '/ssma/' . $id);
        }
        if ($decisao === 'nao_apto' && $obs === null) {
            $this->falha('Justifique o motivo da reprovação.', '/ssma/' . $id);
        }
        // Pessoa vinculada (se houver) — por vínculo ou CPF
        $pessoaId = $s['pessoa_id'];
        if (!$pessoaId && Crypto::cpfValido((string) $s['visitante_doc'])) {
            $pessoaId = Database::value('SELECT id FROM pessoas WHERE cpf_hash = ?', [Crypto::cpfHash($s['visitante_doc'])]) ?: null;
        }

        $vid = Uuid::v4();
        Database::transaction(function () use ($vid, $id, $pessoaId, $decisao, $obs, $checklist, $s, $u): void {
            Database::insert('verificacoes_ssma', [
                'id'                  => $vid,
                'solicitacao_id'      => $id,
                'pessoa_id'           => $pessoaId,
                'status'              => $decisao,
                'observacao'          => $obs,
                'checklist'           => json_encode($checklist),
                'validade_ate'        => $s['data_prevista'], // validade por VISITA
                'verificado_por'      => $u['id'],
                'verificado_por_nome' => $u['nome'],
            ]);
            if ($decisao === 'apto') {
                Database::update('solicitacoes_acesso', ['status' => 'pendente', 'status_observacao' => 'Apto pelo SSMA'], 'id = ?', [$id]);
            } else {
                Database::update('solicitacoes_acesso', [
                    'status' => 'recusada', 'status_observacao' => 'Reprovado pelo SSMA',
                    'motivo_recusa' => $obs, 'autorizado_por' => $u['id'], 'autorizado_em' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
            }
            Audit::log('verificacoes_ssma', $vid, 'INSERT', [
                'solicitacao_id' => $id, 'status' => $decisao, 'validade_ate' => $s['data_prevista'], 'checklist' => $checklist,
            ]);
        });
        $this->ok($decisao === 'apto' ? 'Marcado como APTO. A solicitação segue para autorização da área.' : 'Marcado como NÃO APTO. Solicitação recusada.', '/ssma');
    }

    private function carregar(string $id): array
    {
        $this->uuidOu404($id);
        return Database::one(
            'SELECT s.*, a.nome AS area_nome FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id WHERE s.id = ?',
            [$id]
        ) ?? Http::abort(404, 'Solicitação não encontrada.');
    }
}
