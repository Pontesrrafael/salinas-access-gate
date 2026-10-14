<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Services\Infleet;
use App\Services\InfleetSincronizacao;
use RuntimeException;

final class InfleetController extends Controller
{
    public function index(): void
    {
        Auth::authorize('infleet.ver');
        [$pagina, $limite, $offset] = $this->paginacao();
        $veiculos = Database::all('SELECT i.*, v.cor, v.ativo AS ativo_local FROM infleet_veiculos i LEFT JOIN veiculos v ON v.id = i.veiculo_id ORDER BY i.placa, i.id LIMIT ' . $limite . ' OFFSET ' . $offset);
        $motoristas = Database::all('SELECT i.*, p.nome AS nome_local FROM infleet_motoristas i LEFT JOIN pessoas p ON p.id = i.pessoa_id ORDER BY i.nome, i.id LIMIT ' . $limite . ' OFFSET ' . $offset);
        foreach ($motoristas as &$m) {
            $m['cpf_mascarado'] = Crypto::maskCpf(Crypto::decryptCpf($m['cpf_enc']));
            unset($m['cpf_enc'], $m['cpf_hash']);
        }
        unset($m);
        $this->view('infleet/index', [
            'titulo' => 'Infleet', 'descricao' => 'Frota, motoristas e percursos', 'habilitada' => Infleet::habilitada(),
            'ultima' => Database::one('SELECT * FROM infleet_sincronizacoes ORDER BY concluido_em DESC, id DESC LIMIT 1'),
            'veiculos' => $veiculos, 'motoristas' => $motoristas, 'pagina' => $pagina,
            'mais' => (int) Database::value('SELECT GREATEST((SELECT COUNT(*) FROM infleet_veiculos), (SELECT COUNT(*) FROM infleet_motoristas))') > $offset + $limite,
        ]);
    }

    public function sincronizar(): void
    {
        Auth::authorize('infleet.gerir');
        set_time_limit(300);
        try {
            $this->limitar('sync', 1, 1);
            $r = (new InfleetSincronizacao())->executar();
        } catch (RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            Http::redirect('/infleet');
        }
        $this->ok("Sincronizados {$r['veiculos']} veículos e {$r['motoristas']} motoristas. {$r['pendencias']} vínculos pendentes de conferência.", '/infleet');
    }

    public function cadastrarMotorista(): void
    {
        Auth::authorize('infleet.gerir');
        try {
            $id = InfleetSincronizacao::cadastrarMotorista(Http::str('infleet_id'), Http::str('categoria'), Http::nstr('empresa', 150));
        } catch (RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            Http::redirect('/infleet');
        }
        $this->ok('Motorista vinculado. Confira o cadastro, os documentos e a situação de acesso.', '/pessoas/' . $id);
    }

    public function percursos(): void
    {
        Auth::authorize('infleet.ver');
        $id = Http::str('veiculo');
        $veiculo = $id !== '' ? Database::one('SELECT * FROM infleet_veiculos WHERE id = ?', [$id]) : null;
        $this->view('infleet/percursos', [
            'titulo' => 'Percursos Infleet', 'descricao' => 'Histórico de viagens e trajeto GPS · Horário de Mato Grosso',
            'veiculo' => $veiculo,
            'opcoes' => Database::all('SELECT id, placa, marca_modelo FROM infleet_veiculos ORDER BY placa'),
            'inicio' => date('Y-m-d') . 'T00:00', 'fim' => date('Y-m-d\TH:i'),
            'styles' => ['vendor/leaflet/leaflet.css', 'css/infleet.css'],
            'scripts' => ['vendor/leaflet/leaflet.js', 'js/infleet.js'],
            'googleMapsKey' => (App::config('google_maps')['enabled'] ?? false)
                ? trim((string) (App::config('google_maps')['browser_key'] ?? '')) : '',
        ]);
    }

    public function dadosPercursos(): void
    {
        Auth::authorize('infleet.ver');
        header('Cache-Control: no-store');
        try {
            $id = Http::str('veiculo');
            $v = Database::one('SELECT id FROM infleet_veiculos WHERE id = ?', [$id]);
            if (!$v) { Http::json(['erro' => 'Selecione um veículo sincronizado.'], 422); }
            $periodo = Infleet::periodo(Http::str('inicio'), Http::str('fim'));
            $this->limitar('percursos', 10, 1);
            $result = (new Infleet())->percursos($id, $periodo);
            Audit::log('infleet', null, 'CONSULTA_PERCURSO', ['infleet_id' => $id, 'periodo' => $periodo]);
            Http::json($result);
        } catch (\InvalidArgumentException $e) {
            Http::json(['erro' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            Http::json(['erro' => $e->getMessage()], 503);
        }
    }

    private function limitar(string $operacao, int $max, int $minutos): void
    {
        $key = 'infleet:' . $operacao . ':' . Auth::id();
        if (RateLimiter::tooMany($key, $max, $minutos)) {
            throw new RuntimeException('Aguarde um minuto antes de consultar novamente.');
        }
        RateLimiter::hit($key);
    }
}
