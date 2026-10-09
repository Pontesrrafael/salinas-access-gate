<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $totais = Database::one(
            "SELECT SUM(sentido = 'entrada') AS entradas, SUM(sentido = 'saida') AS saidas
               FROM registros_acesso WHERE data_hora >= CURDATE()"
        ) ?? [];
        $presencaPorLocal = Database::all(
            "SELECT l.id, l.nome, l.tipo, COUNT(p.pessoa_id) AS presentes
               FROM locais l LEFT JOIN vw_presenca_dentro p ON p.local_id = l.id
              WHERE l.ativo = 1 GROUP BY l.id, l.nome, l.tipo ORDER BY l.nome"
        );
        $ultimos = can('registros.ver') ? Database::all(
            'SELECT r.id, r.sentido, r.data_hora, r.destino, p.nome AS pessoa_nome, l.nome AS local_nome, v.placa
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
               LEFT JOIN veiculos v ON v.id = r.veiculo_id
               JOIN locais l ON l.id = r.local_id
              ORDER BY r.data_hora DESC, r.seq DESC LIMIT 10'
        ) : [];
        $alertasDocs = Database::all(
            "SELECT d.tipo, d.validade, p.id AS pessoa_id, p.nome, p.empresa, p.categoria
               FROM documentos d JOIN pessoas p ON p.id = d.pessoa_id
              WHERE p.ativo = 1 AND d.validade <= (CURDATE() + INTERVAL 30 DAY)
              ORDER BY d.validade LIMIT 15"
        );
        $pendentes = (int) Database::value(
            "SELECT COUNT(*) FROM solicitacoes_acesso WHERE status IN ('pendente','aguardando_ssma') AND aguardando_preenchimento = 0"
        );
        $aguardandoSsma = (int) Database::value("SELECT COUNT(*) FROM solicitacoes_acesso WHERE status = 'aguardando_ssma'");
        $visitasHoje = (int) Database::value("SELECT COUNT(*) FROM solicitacoes_acesso WHERE status = 'aprovada' AND data_prevista = CURDATE()");

        $this->view('dashboard/index', [
            'titulo'           => 'Painel de Controle de Acesso',
            'descricao'        => 'Visão operacional do dia — ' . date('d/m/Y'),
            'totais'           => $totais,
            'presencaPorLocal' => $presencaPorLocal,
            'ultimos'          => $ultimos,
            'alertasDocs'      => $alertasDocs,
            'pendentes'        => $pendentes,
            'aguardandoSsma'   => $aguardandoSsma,
            'visitasHoje'      => $visitasHoje,
        ]);
    }
}
