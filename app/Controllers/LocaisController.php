<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;

final class LocaisController extends Controller
{
    public function index(): void
    {
        Auth::authorize('locais.gerir');
        $this->view('locais/index', [
            'titulo'    => 'Locais / Portarias',
            'descricao' => 'Pontos de controle de acesso (mina e escritórios)',
            'rows'      => Database::all(
                'SELECT l.*, (SELECT COUNT(*) FROM registros_acesso r WHERE r.local_id = l.id) AS registros
                   FROM locais l ORDER BY l.ativo DESC, l.nome'
            ),
            'editar'    => Http::str('editar'),
        ]);
    }

    public function salvar(?string $id = null): void
    {
        Auth::authorize('locais.gerir');
        $nome = Http::nstr('nome', 150);
        $tipo = Http::str('tipo');
        if ($nome === null || !array_key_exists($tipo, TIPOS_LOCAL)) {
            $this->falha('Informe nome e tipo do local.', '/locais');
        }
        $d = ['nome' => $nome, 'tipo' => $tipo, 'endereco' => Http::nstr('endereco', 255)];
        if ($id === null) {
            $id = Uuid::v4();
            Database::insert('locais', ['id' => $id, ...$d]);
            Audit::log('locais', $id, 'INSERT', $d);
            $this->ok('Local cadastrado.', '/locais');
        }
        $this->uuidOu404($id);
        $d['ativo'] = Http::bool('ativo') ? 1 : 0;
        Database::update('locais', $d, 'id = ?', [$id]);
        Audit::log('locais', $id, 'UPDATE', $d);
        $this->ok('Local atualizado.', '/locais');
    }

    public function excluir(string $id): void
    {
        Auth::authorize('locais.excluir');
        $this->uuidOu404($id);
        if (Database::value('SELECT 1 FROM registros_acesso WHERE local_id = ? LIMIT 1', [$id])) {
            $this->falha('Local possui registros de acesso. Desative-o em vez de excluir.', '/locais');
        }
        $nome = Database::value('SELECT nome FROM locais WHERE id = ?', [$id]);
        Database::run('DELETE FROM locais WHERE id = ?', [$id]);
        Audit::log('locais', $id, 'DELETE', ['nome' => $nome]);
        $this->ok('Local excluído.', '/locais');
    }
}
