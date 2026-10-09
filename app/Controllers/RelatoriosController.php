<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Services\Csv;

final class RelatoriosController extends Controller
{
    public function index(): void
    {
        Auth::authorize('relatorios.ver');
        [$where, $params, $f] = $this->filtros();
        $rows = Database::all($this->sql($where) . ' LIMIT 500', $params);
        $resumo = Database::one(
            "SELECT COUNT(*) AS total, SUM(r.sentido = 'entrada') AS entradas, SUM(r.sentido = 'saida') AS saidas,
                    COUNT(DISTINCT r.pessoa_id) AS pessoas, COUNT(DISTINCT r.veiculo_id) AS veiculos
               FROM registros_acesso r LEFT JOIN pessoas p ON p.id = r.pessoa_id WHERE " . $where,
            $params
        );
        $this->view('relatorios/index', [
            'titulo'    => 'Relatórios de Movimentação',
            'descricao' => 'Filtre e exporte os registros de acesso (até 500 linhas na tela; CSV completo)',
            'rows'      => $rows,
            'resumo'    => $resumo,
            'f'         => $f,
            'locais'    => Database::all('SELECT id, nome FROM locais ORDER BY nome'),
        ]);
    }

    public function csv(): void
    {
        Auth::authorize('relatorios.ver');
        [$where, $params, $f] = $this->filtros();
        $rows = Database::run($this->sql($where), $params);
        Audit::log('relatorios', null, 'EXPORT_CSV', ['filtros' => $f]);
        Csv::download('relatorio-acessos-' . date('Ymd-His') . '.csv',
            ['Data/Hora', 'Sentido', 'Pessoa', 'Categoria', 'Empresa', 'Veículo', 'Local', 'Destino', 'Responsável interno', 'Porteiro', 'Observações'],
            (function () use ($rows) {
                while ($r = $rows->fetch()) {
                    yield [fmt_data_hora($r['data_hora']), $r['sentido'] === 'entrada' ? 'Entrada' : 'Saída', $r['pessoa_nome'],
                        CATEGORIAS[$r['categoria'] ?? ''] ?? '', $r['pessoa_empresa'], $r['placa'], $r['local_nome'], $r['destino'],
                        $r['responsavel_interno_nome'], $r['porteiro_nome'], $r['observacoes']];
                }
            })()
        );
    }

    private function sql(string $where): string
    {
        return 'SELECT r.id, r.data_hora, r.sentido, r.destino, r.observacoes, r.responsavel_interno_nome,
                       p.nome AS pessoa_nome, p.categoria, p.empresa AS pessoa_empresa, v.placa,
                       l.nome AS local_nome, u.nome AS porteiro_nome
                  FROM registros_acesso r
                  LEFT JOIN pessoas p ON p.id = r.pessoa_id
                  LEFT JOIN veiculos v ON v.id = r.veiculo_id
                  JOIN locais l ON l.id = r.local_id
                  LEFT JOIN usuarios u ON u.id = r.porteiro_id
                 WHERE ' . $where . ' ORDER BY r.data_hora DESC, r.seq DESC';
    }

    private function filtros(): array
    {
        $f = [
            'inicio'    => Http::str('inicio', date('Y-m-01')),
            'fim'       => Http::str('fim', date('Y-m-d')),
            'local_id'  => Http::str('local_id'),
            'sentido'   => Http::str('sentido'),
            'categoria' => Http::str('categoria'),
            'q'         => Http::str('q'),
        ];
        $where = ['1 = 1'];
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['inicio'])) {
            $where[] = 'r.data_hora >= ?';
            $params[] = $f['inicio'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['fim'])) {
            $where[] = 'r.data_hora <= ?';
            $params[] = $f['fim'] . ' 23:59:59';
        }
        if ($f['local_id'] !== '') {
            $where[] = 'r.local_id = ?';
            $params[] = $f['local_id'];
        }
        if (in_array($f['sentido'], ['entrada', 'saida'], true)) {
            $where[] = 'r.sentido = ?';
            $params[] = $f['sentido'];
        }
        if (array_key_exists($f['categoria'], CATEGORIAS)) {
            $where[] = 'p.categoria = ?';
            $params[] = $f['categoria'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(p.nome LIKE ? OR p.empresa LIKE ? OR r.destino LIKE ? OR r.veiculo_id IN (SELECT id FROM veiculos WHERE placa LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params, $f];
    }
}
