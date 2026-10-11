<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Core\Session;

final class AuthController extends Controller
{
    public function inicio(): void
    {
        Http::redirect(Auth::check() ? '/painel' : '/login');
    }

    public function formLogin(): void
    {
        if (Auth::check()) {
            Http::redirect('/painel');
        }
        $this->view('auth/login', [
            'titulo' => 'Entrar', 'logoVertical' => true, 'ldap' => \App\Services\Ldap::disponivel(),
        ], 'layout/simples');
    }

    public function login(): void
    {
        $email = mb_strtolower(Http::str('email'));
        $senha = (string) ($_POST['senha'] ?? '');
        $cfg = App::config('seguranca');
        $chave = 'login:' . hash('sha256', $email . '|' . Http::clientIp());
        $chaveIp = 'login-ip:' . Http::clientIp();
        // Por conta, de qualquer IP: freia tentativa distribuída contra um mesmo usuário
        $chaveConta = 'login-conta:' . hash('sha256', $email);

        if (RateLimiter::tooMany($chave, $cfg['login_max'], $cfg['login_janela_min'])
            || RateLimiter::tooMany($chaveIp, $cfg['login_max'] * 4, $cfg['login_janela_min'])
            || ($email !== '' && RateLimiter::tooMany($chaveConta, $cfg['login_max'] * 3, $cfg['login_janela_min']))) {
            Audit::log('auth', null, 'LOGIN_BLOQUEADO', ['email' => $email], null);
            $this->falha("Muitas tentativas. Aguarde {$cfg['login_janela_min']} minutos e tente novamente.", '/login');
        }

        $r = ($email !== '' && $senha !== '') ? Auth::attempt($email, $senha) : ['usuario' => null, 'erro' => null];
        $u = $r['usuario'];
        if ($u === null) {
            RateLimiter::hit($chave);
            RateLimiter::hit($chaveIp);
            if ($email !== '') {
                RateLimiter::hit($chaveConta);
            }
            Audit::log('auth', null, 'LOGIN_FALHA', ['login' => $email, 'motivo' => $r['erro']], null);
            $this->falha($r['erro'] ?? 'Usuário ou senha inválidos.', '/login');
        }
        if ((int) $u['ativo'] !== 1) {
            Audit::log('auth', $u['id'], 'LOGIN_INATIVO', [], $u['id']);
            $this->falha('Usuário desativado. Procure o Admin/TI.', '/login');
        }

        RateLimiter::clear($chave);
        RateLimiter::clear($chaveConta);
        Auth::login($u);
        Audit::log('auth', $u['id'], 'LOGIN', ['origem' => $u['auth_origem'] ?? 'local'], $u['id']);

        if ((int) $u['must_change_password'] === 1) {
            Http::redirect('/trocar-senha');
        }
        $destino = Session::get('intended', '/painel');
        Session::forget('intended');
        // Só redireciona para caminhos internos
        if (!is_string($destino) || !str_starts_with($destino, '/') || str_starts_with($destino, '//')) {
            $destino = '/painel';
        }
        $base = Http::basePath();
        if ($base !== '' && str_starts_with($destino, $base)) {
            $destino = substr($destino, strlen($base)) ?: '/painel';
        }
        Http::redirect($destino);
    }

    public function logout(): void
    {
        if (Auth::check()) {
            Audit::log('auth', Auth::id(), 'LOGOUT');
        }
        Auth::logout();
        Session::start();
        Session::flash('info', 'Você saiu do sistema.');
        Http::redirect('/login');
    }

    public function formTrocarSenha(): void
    {
        $u = Auth::requireLogin();
        if (($u['auth_origem'] ?? 'local') === 'ldap') {
            Session::flash('info', 'Sua senha é a mesma da rede (Active Directory). Para alterá-la, use Ctrl+Alt+Del no Windows ou procure o Admin/TI.');
            Http::redirect('/painel');
        }
        $this->view('auth/trocar-senha', [
            'titulo'      => 'Trocar senha',
            'obrigatoria' => (int) $u['must_change_password'] === 1,
        ], 'layout/simples');
    }

    public function trocarSenha(): void
    {
        $u = Auth::requireLogin();
        if (($u['auth_origem'] ?? 'local') === 'ldap') {
            Http::redirect('/painel');
        }
        $atual = (string) ($_POST['senha_atual'] ?? '');
        $nova = (string) ($_POST['nova_senha'] ?? '');
        $conf = (string) ($_POST['senha_confirmacao'] ?? '');

        $hash = (string) Database::value('SELECT senha_hash FROM usuarios WHERE id = ?', [$u['id']]);
        if (!password_verify($atual, $hash)) {
            $this->falha('Senha atual incorreta.', '/trocar-senha');
        }
        if ($nova !== $conf) {
            $this->falha('A confirmação não confere com a nova senha.', '/trocar-senha');
        }
        if (password_verify($nova, $hash)) {
            $this->falha('A nova senha deve ser diferente da atual.', '/trocar-senha');
        }
        if ($erro = Auth::validarSenha($nova, $u['email'])) {
            $this->falha($erro, '/trocar-senha');
        }
        Database::update('usuarios', [
            'senha_hash'           => Auth::hash($nova),
            'must_change_password' => 0,
            'senha_alterada_em'    => date('Y-m-d H:i:s'),
        ], 'id = ?', [$u['id']]);
        Audit::log('usuarios', $u['id'], 'TROCA_SENHA');
        Session::regenerate();
        Session::set('login_em', time() + 1); // esta sessão continua; as demais do usuário são encerradas
        $this->ok('Senha alterada com sucesso.', '/painel');
    }
}
