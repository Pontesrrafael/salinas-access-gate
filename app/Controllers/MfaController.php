<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Services\Settings;
use App\Services\Totp;

/**
 * Autenticação em dois fatores (TOTP) para contas locais — ISO 27001 A.8.5.
 * Contas do Active Directory seguem a política de MFA da rede.
 */
final class MfaController extends Controller
{
    private const VALIDADE_DESAFIO = 300; // segundos entre a senha e o código

    /* ---- 2ª etapa do login ---- */
    public function formDesafio(): void
    {
        $this->pendente();
        $this->view('auth/mfa', ['titulo' => 'Verificação em duas etapas'], 'layout/simples');
    }

    public function desafio(): void
    {
        $u = $this->pendente();
        $chave = 'mfa:' . $u['id'];
        if (RateLimiter::tooMany($chave, 5, 15)) {
            Session::forget('mfa_pendente');
            Audit::log('auth', $u['id'], 'MFA_BLOQUEADO', [], $u['id']);
            $this->falha('Muitos códigos errados. Aguarde 15 minutos e entre de novo.', '/login');
        }
        $segredo = Crypto::decryptSegredo($u['mfa_segredo_enc']);
        $passo = $segredo ? Totp::verificar($segredo, Http::str('codigo'), $u['mfa_ultimo_passo'] !== null ? (int) $u['mfa_ultimo_passo'] : null) : null;
        if ($passo === null) {
            RateLimiter::hit($chave);
            Audit::log('auth', $u['id'], 'MFA_FALHA', [], $u['id']);
            $this->falha('Código inválido ou já usado. Confira o horário do celular e digite o código atual.', '/login/mfa');
        }
        RateLimiter::clear($chave);
        Database::update('usuarios', ['mfa_ultimo_passo' => $passo], 'id = ?', [$u['id']]);
        Session::forget('mfa_pendente');
        AuthController::concluir($u, 'mfa');
    }

    /* ---- Cadastro e desligamento pelo próprio usuário ---- */
    public function conta(): void
    {
        $u = Auth::requireLogin();
        $this->somenteLocal($u);
        $novo = null;
        if ((int) $u['mfa_ativo'] !== 1) {
            $segredo = Session::get('mfa_novo') ?: Totp::novoSegredo();
            Session::set('mfa_novo', $segredo);
            $novo = ['segredo' => Totp::formatar($segredo), 'uri' => Totp::uri($segredo, $u['email'])];
        }
        $this->view('auth/conta-mfa', [
            'titulo'      => 'Verificação em duas etapas',
            'descricao'   => 'Proteja sua conta com um código do celular',
            'ativo'       => (int) $u['mfa_ativo'] === 1,
            'ativadoEm'   => $u['mfa_ativado_em'],
            'novo'        => $novo,
            'obrigatorio' => $this->obrigatorio($u),
        ]);
    }

    public function ativar(): void
    {
        $u = Auth::requireLogin();
        $this->somenteLocal($u);
        $segredo = (string) Session::get('mfa_novo', '');
        $passo = $segredo !== '' ? Totp::verificar($segredo, Http::str('codigo')) : null;
        if ($passo === null) {
            $this->falha('Código inválido. Confira se digitou o código atual mostrado no aplicativo.', '/conta/mfa');
        }
        Database::update('usuarios', [
            'mfa_segredo_enc' => Crypto::encryptSegredo($segredo), 'mfa_ativo' => 1,
            'mfa_ultimo_passo' => $passo, 'mfa_ativado_em' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$u['id']]);
        Session::forget('mfa_novo');
        Session::regenerate();
        Audit::log('usuarios', $u['id'], 'MFA_ATIVADO');
        $this->ok('Verificação em duas etapas ativada. A partir de agora o login pede o código do celular.', '/painel');
    }

    public function desativar(): void
    {
        $u = Auth::requireLogin();
        $this->somenteLocal($u);
        if ($this->obrigatorio($u)) {
            $this->falha('Para o seu perfil a verificação em duas etapas é obrigatória. Em caso de troca de celular, peça ao Admin/TI para redefinir.', '/conta/mfa');
        }
        $hash = (string) Database::value('SELECT senha_hash FROM usuarios WHERE id = ?', [$u['id']]);
        if (!password_verify((string) ($_POST['senha'] ?? ''), $hash)) {
            $this->falha('Senha incorreta.', '/conta/mfa');
        }
        Database::update('usuarios', ['mfa_ativo' => 0, 'mfa_segredo_enc' => null, 'mfa_ultimo_passo' => null, 'mfa_ativado_em' => null], 'id = ?', [$u['id']]);
        Audit::log('usuarios', $u['id'], 'MFA_DESATIVADO');
        $this->ok('Verificação em duas etapas desativada.', '/conta/mfa');
    }

    /* ------------------------------------------------------------------ */
    /** Usuário que acertou a senha e ainda não digitou o código. */
    private function pendente(): array
    {
        $p = Session::get('mfa_pendente');
        if (!is_array($p) || (time() - (int) ($p['em'] ?? 0)) > self::VALIDADE_DESAFIO) {
            Session::forget('mfa_pendente');
            $this->falha('Entre novamente com usuário e senha.', '/login');
        }
        $u = Database::one('SELECT * FROM usuarios WHERE id = ? AND ativo = 1 AND mfa_ativo = 1', [(string) $p['uid']]);
        if (!$u) {
            Session::forget('mfa_pendente');
            $this->falha('Entre novamente com usuário e senha.', '/login');
        }
        return $u;
    }

    private function somenteLocal(array $u): void
    {
        if (($u['auth_origem'] ?? 'local') !== 'local') {
            $this->falha('Sua conta usa o login da rede (Active Directory); o segundo fator segue a política da rede.', '/painel');
        }
    }

    private function obrigatorio(array $u): bool
    {
        // Vale para quem TEM o perfil Admin (mesmo com outro perfil ativo), como em Auth::requireLogin.
        return Auth::mfaObrigatorio();
    }
}
