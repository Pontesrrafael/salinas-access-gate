<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Services\Settings;

final class PresencaController extends Controller
{
    public function index(): void
    {
        Auth::authorize('registros.ver');
        $this->view('presenca/index', [
            'titulo'    => 'Quem está no site agora',
            'descricao' => 'Pessoas cujo último registro no local é uma entrada · atualiza a cada 60 s',
            ...$this->dados(),
        ]);
    }

    /** Lista de evacuação / contagem para emergência. */
    public function imprimir(): void
    {
        Auth::authorize('registros.ver');
        $d = $this->dados();
        Audit::log('presenca', null, 'IMPRESSAO_LISTA', ['local_id' => $d['localId'] ?: 'todos', 'total' => count($d['lista'])]);
        $this->view('presenca/imprimir', ['titulo' => 'Lista de presença / evacuação', ...$d]);
    }

    private function dados(): array
    {
        $localId = Http::str('local_id');
        $q = Http::str('q');
        $where = ['1 = 1'];
        $params = [];
        if ($localId !== '') {
            $where[] = 'p.local_id = ?';
            $params[] = $localId;
        }
        if ($q !== '') {
            $where[] = '(p.pessoa_nome LIKE ? OR p.pessoa_empresa LIKE ? OR p.veiculo_placa LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $lista = Database::all(
            'SELECT p.*, TIMESTAMPDIFF(MINUTE, p.entrada_em, NOW()) AS minutos
               FROM vw_presenca_dentro p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.local_nome, p.pessoa_nome',
            $params
        );
        $porCategoria = ['colaborador' => 0, 'visitante' => 0, 'terceiro' => 0];
        foreach ($lista as $r) {
            $porCategoria[$r['pessoa_categoria']] = ($porCategoria[$r['pessoa_categoria']] ?? 0) + 1;
        }
        return [
            'lista'         => $lista,
            'locais'        => Database::all('SELECT id, nome FROM locais WHERE ativo = 1 ORDER BY nome'),
            'localId'       => $localId,
            'q'             => $q,
            'porCategoria'  => $porCategoria,
            'alertaHoras'   => Settings::int('alerta_permanencia_horas', 8),
        ];
    }
}
