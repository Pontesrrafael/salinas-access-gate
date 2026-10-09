<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;

final class AuditoriaController extends Controller
{
    public function index(): void
    {
        Auth::authorize('auditoria.ver');
        [$pagina, $limite, $offset] = $this->paginacao(100);
        $f = [
            'entidade' => Http::str('entidade'),
            'acao'     => Http::str('acao'),
            'usuario'  => Http::str('usuario'),
            'inicio'   => Http::str('inicio'),
            'fim'      => Http::str('fim'),
        ];
        $where = ['1 = 1'];
        $params = [];
        if ($f['entidade'] !== '') {
            $where[] = 'a.entidade = ?';
            $params[] = $f['entidade'];
        }
        if ($f['acao'] !== '') {
            $where[] = 'a.acao = ?';
            $params[] = $f['acao'];
        }
        if ($f['usuario'] !== '') {
            $where[] = 'a.usuario_id = ?';
            $params[] = $f['usuario'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['inicio'])) {
            $where[] = 'a.data_hora >= ?';
            $params[] = $f['inicio'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['fim'])) {
            $where[] = 'a.data_hora <= ?';
            $params[] = $f['fim'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::value('SELECT COUNT(*) FROM audit_log a WHERE ' . $w, $params);
        $rows = Database::all(
            'SELECT a.*, u.nome AS usuario_nome FROM audit_log a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE ' . $w . ' ORDER BY a.seq DESC LIMIT ' . $limite . ' OFFSET ' . $offset,
            $params
        );
        $this->view('auditoria/index', [
            'titulo'     => 'Auditoria',
            'descricao'  => 'Registro imutável de ações no sistema (append-only)',
            'rows'       => $rows,
            'f'          => $f,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int) ceil($total / $limite)),
            'entidades'  => array_column(Database::all('SELECT DISTINCT entidade FROM audit_log ORDER BY entidade'), 'entidade'),
            'listaAcoes' => array_column(Database::all('SELECT DISTINCT acao FROM audit_log ORDER BY acao'), 'acao'),
            'usuarios'   => Database::all('SELECT id, nome FROM usuarios ORDER BY nome'),
        ]);
    }
}
