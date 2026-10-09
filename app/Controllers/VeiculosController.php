<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;

final class VeiculosController extends Controller
{
    public function index(): void
    {
        Auth::authorize('veiculos.ver');
        $q = Http::str('q');
        $sit = Http::str('situacao', 'ativos');
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $placa = '%' . addcslashes(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '', '%_\\') . '%';
            $where[] = '(placa LIKE ? OR marca_modelo LIKE ? OR empresa LIKE ? OR tag LIKE ?)';
            array_push($params, $placa, $like, $like, $like);
        }
        if ($sit === 'ativos') {
            $where[] = 'ativo = 1';
        } elseif ($sit === 'inativos') {
            $where[] = 'ativo = 0';
        }
        $this->view('veiculos/index', [
            'titulo'    => 'Veículos',
            'descricao' => 'Frota própria, terceiros e visitantes',
            'rows'      => Database::all('SELECT * FROM veiculos WHERE ' . implode(' AND ', $where) . ' ORDER BY placa LIMIT 500', $params),
            'q'         => $q,
            'situacao'  => $sit,
        ]);
    }

    public function novo(): void
    {
        Auth::authorize('veiculos.criar');
        $this->view('veiculos/form', ['titulo' => 'Novo veículo', 'v' => null]);
    }

    public function editar(string $id): void
    {
        Auth::authorize('veiculos.ver');
        $v = $this->carregar($id);
        $this->view('veiculos/form', ['titulo' => 'Veículo ' . $v['placa'], 'v' => $v]);
    }

    public function criar(): void
    {
        Auth::authorize('veiculos.criar');
        $d = $this->dados('/veiculos/novo', null);
        $id = Uuid::v4();
        Database::insert('veiculos', ['id' => $id, ...$d]);
        Audit::log('veiculos', $id, 'INSERT', ['placa' => $d['placa']]);
        $this->ok('Veículo cadastrado.', '/veiculos');
    }

    public function atualizar(string $id): void
    {
        Auth::authorize('veiculos.editar');
        $this->carregar($id);
        $d = $this->dados('/veiculos/' . $id, $id);
        $d['ativo'] = Http::bool('ativo') ? 1 : 0;
        Database::update('veiculos', $d, 'id = ?', [$id]);
        Audit::log('veiculos', $id, 'UPDATE', ['placa' => $d['placa'], 'ativo' => $d['ativo']]);
        $this->ok('Veículo atualizado.', '/veiculos');
    }

    public function excluir(string $id): void
    {
        Auth::authorize('veiculos.excluir');
        $v = $this->carregar($id);
        if (Database::value('SELECT 1 FROM registros_acesso WHERE veiculo_id = ? LIMIT 1', [$id])) {
            $this->falha('Veículo possui registros de acesso (histórico imutável). Inative-o em vez de excluir.', '/veiculos/' . $id);
        }
        Database::run('DELETE FROM veiculos WHERE id = ?', [$id]);
        Audit::log('veiculos', $id, 'DELETE', ['placa' => $v['placa']]);
        $this->ok('Veículo excluído.', '/veiculos');
    }

    private function dados(string $volta, ?string $id): array
    {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Http::str('placa')) ?? '');
        if (!preg_match('/^[A-Z0-9]{5,10}$/', $placa)) {
            $this->falha('Placa inválida (use letras e números, ex.: ABC1D23).', $volta);
        }
        if (Database::value('SELECT 1 FROM veiculos WHERE placa = ? AND id <> ?', [$placa, $id ?? ''])) {
            $this->falha('Placa já cadastrada.', $volta);
        }
        $tag = Http::nstr('tag', 60);
        if ($tag !== null && Database::value('SELECT 1 FROM veiculos WHERE tag = ? AND id <> ?', [$tag, $id ?? ''])) {
            $this->falha('TAG já utilizada por outro veículo.', $volta);
        }
        $tipo = Http::str('tipo', 'carro');
        $validade = Http::str('comissionamento_validade');
        return [
            'placa'                    => $placa,
            'tipo'                     => array_key_exists($tipo, TIPOS_VEICULO) ? $tipo : 'carro',
            'marca_modelo'             => Http::nstr('marca_modelo', 120),
            'cor'                      => Http::nstr('cor', 40),
            'empresa'                  => Http::nstr('empresa', 150),
            'tag'                      => $tag,
            'comissionado_mina'        => Http::bool('comissionado_mina') ? 1 : 0,
            'comissionamento_validade' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade) ? $validade : null,
            'observacoes'              => Http::nstr('observacoes', 2000),
        ];
    }

    private function carregar(string $id): array
    {
        $this->uuidOu404($id);
        return Database::one('SELECT * FROM veiculos WHERE id = ?', [$id]) ?? Http::abort(404, 'Veículo não encontrado.');
    }
}
