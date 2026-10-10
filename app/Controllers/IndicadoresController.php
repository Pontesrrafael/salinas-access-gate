<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\View;
use App\Services\Indicadores;
use App\Services\Settings;

/** Indicadores (modo dashboard) com filtro de período/local, atualização automática e Modo TV. */
final class IndicadoresController extends Controller
{
    public function index(): void
    {
        Auth::authorize('indicadores.ver');
        $f = Indicadores::filtro($_GET);
        $dados = $this->dados($f);

        if (Http::bool('parcial')) {
            // Atualização automática: devolve só o miolo da página (sem layout)
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
            echo View::partial('indicadores/_conteudo', $dados);
            exit;
        }

        $atualizar = Http::str('atualizar');
        $this->view('indicadores/index', [
            'titulo'      => 'Indicadores',
            'descricao'   => 'Visão consolidada do controle de acesso · Horário de Mato Grosso – UTC-4',
            'modoTv'      => Http::bool('tv'),
            'atualizar'   => array_key_exists((int) $atualizar, Indicadores::ATUALIZACOES) && $atualizar !== '' ? (int) $atualizar : null,
            'locais'      => Database::all('SELECT id, nome FROM locais WHERE ativo = 1 ORDER BY nome'),
            ...$dados,
        ]);
    }

    private function dados(array $f): array
    {
        $resumo = Indicadores::resumo($f['ini'], $f['fim'], $f['localId']);
        $anterior = Indicadores::resumo($f['antIni'], $f['antFim'], $f['localId']);
        $localNome = $f['localId'] ? Database::value('SELECT nome FROM locais WHERE id = ?', [$f['localId']]) : null;

        return [
            'f'           => $f,
            'periodoTxt'  => Indicadores::descreverPeriodo($f),
            'localNome'   => $localNome,
            'resumo'      => $resumo,
            'anterior'    => $anterior,
            'permanencia' => Indicadores::permanenciaMedia($f['ini'], $f['fim'], $f['localId']),
            'presenca'    => Indicadores::presenca($f['localId']),
            'serie'       => Indicadores::serieEntradas($f),
            'porHora'     => Indicadores::porHoraDoDia($f),
            'empresas'    => Indicadores::topEmpresas($f),
            'solicitacoes' => Indicadores::solicitacoes($f),
            'ssma'        => Indicadores::ssma($f),
            'documentos'  => Indicadores::documentos(),
            'alertas'     => Indicadores::alertas($f['localId'], max(1, Settings::int('alerta_permanencia_horas', 14))),
            'ultimos'     => can('registros.ver') ? Indicadores::ultimos($f, 8) : [],
            'geradoEm'    => date('d/m/Y H:i:s'),
        ];
    }
}
