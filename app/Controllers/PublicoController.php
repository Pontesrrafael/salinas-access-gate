<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Uuid;
use App\Services\Settings;
use Throwable;

/**
 * Páginas sem login: verificação de saúde e formulários externos de solicitação
 * (substitui a Edge Function solicitar-acesso-publico).
 */
final class PublicoController extends Controller
{
    /** Health check para Zabbix/Uptime Kuma: HTTP 200 = ok, 503 = banco indisponível. */
    public function saude(): void
    {
        try {
            Database::value('SELECT 1');
            Http::json(['status' => 'ok', 'banco' => 'ok', 'hora' => date('c')]);
        } catch (Throwable $e) {
            App::log('SAUDE banco indisponível: ' . $e->getMessage());
            Http::json(['status' => 'erro', 'banco' => 'indisponivel', 'hora' => date('c')], 503);
        }
    }

    public function formPublico(): void
    {
        if (!Settings::bool('form_publico_ativo')) {
            Http::abort(404, 'O formulário público de solicitação está desativado.');
        }
        $this->view('publico/form', [
            'titulo'    => 'Solicitar acesso',
            'largo'     => true,
            'token'     => null,
            'fixo'      => null,
            'areas'     => Database::all('SELECT id, nome FROM areas WHERE ativo = 1 ORDER BY nome'),
            'turnstile' => App::config('turnstile')['site_key'],
        ], 'layout/simples');
    }

    public function enviarPublico(): void
    {
        if (!Settings::bool('form_publico_ativo')) {
            Http::abort(404);
        }
        $this->protecoes('/solicitar-acesso');
        $d = $this->dados('/solicitar-acesso');
        $areaId = Http::str('area_id');
        $data = Http::str('data_prevista');
        if (!Uuid::valid($areaId) || !Database::value('SELECT 1 FROM areas WHERE id = ? AND ativo = 1', [$areaId])) {
            $this->falha('Selecione a área que você irá visitar.', '/solicitar-acesso');
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $data);
        if (!$dt || $dt->format('Y-m-d') !== $data || $data < date('Y-m-d')) {
            $this->falha('Informe uma data prevista válida (hoje ou futura).', '/solicitar-acesso');
        }
        $id = Uuid::v4();
        Database::insert('solicitacoes_acesso', [
            'id' => $id, ...$d, 'area_id' => $areaId, 'data_prevista' => $data,
            'status' => $d['atividade_risco'] ? 'aguardando_ssma' : 'pendente', 'origem' => 'publica', 'consentimento_lgpd' => 1,
        ]);
        Audit::log('solicitacoes_acesso', $id, 'INSERT_PUBLICO', ['visitante_nome' => $d['visitante_nome'], 'area_id' => $areaId], null);
        $this->concluido();
    }

    public function formToken(string $token): void
    {
        $s = $this->solicitacaoPorToken($token);
        $this->view('publico/form', [
            'titulo'    => 'Solicitar acesso',
            'largo'     => true,
            'token'     => $token,
            'fixo'      => $s,
            'areas'     => [],
            'turnstile' => App::config('turnstile')['site_key'],
        ], 'layout/simples');
    }

    public function enviarToken(string $token): void
    {
        $s = $this->solicitacaoPorToken($token);
        $volta = '/solicitar-acesso/' . $token;
        $this->protecoes($volta);
        $d = $this->dados($volta);
        $n = Database::update('solicitacoes_acesso', [
            ...$d,
            'consentimento_lgpd'       => 1,
            'aguardando_preenchimento' => 0,
            'status'                   => $d['atividade_risco'] ? 'aguardando_ssma' : 'pendente',
        ], "id = ? AND aguardando_preenchimento = 1 AND status = 'pendente'", [$s['id']]);
        if ($n === 0) {
            Http::abort(409, 'Esta solicitação já foi preenchida.');
        }
        Audit::log('solicitacoes_acesso', $s['id'], 'PREENCHIMENTO_EXTERNO', ['visitante_nome' => $d['visitante_nome']], null);
        $this->concluido();
    }

    /* ------------------------------------------------------------------ */
    private function concluido(): never
    {
        Session::set('publico_ok', true);
        $this->view('publico/concluido', ['titulo' => 'Solicitação enviada'], 'layout/simples');
        exit;
    }

    private function solicitacaoPorToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            Http::abort(404, 'Link inválido.');
        }
        $s = Database::one(
            'SELECT s.id, s.data_prevista, s.link_expira_em, s.status, s.aguardando_preenchimento, a.nome AS area_nome, l.nome AS local_nome
               FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN locais l ON l.id = s.local_id
              WHERE s.token_externo = ?',
            [$token]
        );
        if (!$s) {
            Http::abort(404, 'Link inválido.');
        }
        if ($s['link_expira_em'] && $s['link_expira_em'] < date('Y-m-d H:i:s')) {
            Http::abort(410, 'Este link expirou. Solicite um novo link ao seu contato na Salinas Gold.');
        }
        if ((int) $s['aguardando_preenchimento'] !== 1 || $s['status'] !== 'pendente') {
            Http::abort(409, 'Esta solicitação já foi preenchida.');
        }
        return $s;
    }

    /** Rate limit por IP, honeypot anti-robô e Cloudflare Turnstile (se configurado). */
    private function protecoes(string $volta): void
    {
        $ip = Http::clientIp();
        if (RateLimiter::tooMany('publico:' . $ip, 5, 1) || RateLimiter::tooMany('publico-h:' . $ip, 20, 60)) {
            Http::abort(429, 'Muitas tentativas. Aguarde alguns minutos.');
        }
        RateLimiter::hit('publico:' . $ip);
        RateLimiter::hit('publico-h:' . $ip);
        if (Http::str('website') !== '') {
            $this->concluido(); // robô: finge sucesso sem gravar
        }
        if (!$this->turnstileOk()) {
            $this->falha('Falha na verificação anti-robô. Tente novamente.', $volta);
        }
    }

    private function dados(string $volta): array
    {
        $nome = Http::nstr('visitante_nome', 200);
        $email = Http::nstr('contato_email', 190);
        if ($nome === null || mb_strlen($nome) < 3) {
            $this->falha('Informe o nome completo.', $volta);
        }
        if (!Http::bool('consentimento_lgpd')) {
            $this->falha('É necessário concordar com o tratamento dos dados (LGPD).', $volta);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->falha('E-mail inválido.', $volta);
        }
        $telefone = Http::nstr('contato_telefone', 30);
        if ($telefone === null && $email === null) {
            $this->falha('Informe ao menos um contato (telefone ou e-mail).', $volta);
        }
        return [
            'visitante_nome'   => $nome,
            'visitante_doc'    => Http::nstr('visitante_doc', 30),
            'empresa'          => Http::nstr('empresa', 150),
            'contato_telefone' => $telefone,
            'contato_email'    => $email,
            'tipo'             => Http::str('tipo') === 'terceiro' ? 'terceiro' : 'visitante',
            'motivo'           => Http::nstr('motivo', 2000),
            'atividade_risco'  => Http::bool('atividade_risco') ? 1 : 0,
        ];
    }

    private function turnstileOk(): bool
    {
        $secret = App::config('turnstile')['secret_key'];
        if ($secret === '') {
            return true;
        }
        $resp = Http::str('cf-turnstile-response');
        if ($resp === '' || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $resp, 'remoteip' => Http::clientIp()]),
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        $j = is_string($r) ? json_decode($r, true) : null;
        return is_array($j) && !empty($j['success']);
    }
}
