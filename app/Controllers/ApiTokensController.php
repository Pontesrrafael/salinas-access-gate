<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Http;
use App\Core\Session;
use App\Services\ApiTokens;

/**
 * Configurações → Tokens de API (/configuracoes/api-tokens).
 * Tokens de consulta somente leitura para ferramentas de B.I. O token em claro aparece uma única vez.
 */
final class ApiTokensController extends Controller
{
    /** Validades oferecidas na tela (dias); 0 = sem validade. */
    public const VALIDADES = [30, 90, 180, 365, 0];

    public function index(): void
    {
        Auth::authorize('api.tokens');
        $novo = Session::get('api_token_novo');
        Session::forget('api_token_novo');
        $this->view('configuracoes/api_tokens', [
            'titulo'    => t('Tokens de API'),
            'descricao' => t('Acesso somente leitura para ferramentas de B.I. (Power BI, Excel e similares)'),
            'tokens'    => ApiTokens::listar(),
            'escopos'   => ApiTokens::ESCOPOS,
            'validades' => self::VALIDADES,
            'novo'      => is_array($novo) ? $novo : null,
            'baseApi'   => rtrim(url('/api/v1'), '/'),
        ]);
    }

    public function criar(): void
    {
        $u = Auth::authorize('api.tokens');
        $nome = trim(Http::str('nome'));
        $escopos = array_values(array_intersect(array_keys(ApiTokens::ESCOPOS), (array) ($_POST['escopos'] ?? [])));
        $validade = (int) Http::str('validade', '90');
        if ($nome === '' || mb_strlen($nome) > 120) { $this->falha(t('Informe um nome de até 120 caracteres para identificar o token.'), '/configuracoes/api-tokens'); }
        if ($escopos === []) { $this->falha(t('Selecione ao menos um conjunto de dados.'), '/configuracoes/api-tokens'); }
        if (!in_array($validade, self::VALIDADES, true)) { $validade = 90; }

        $r = ApiTokens::criar($nome, $escopos, $validade === 0 ? null : $validade, (string) $u['id']);
        Session::set('api_token_novo', ['nome' => $nome, 'token' => $r['token']]);
        $this->ok(t('Token criado. Copie agora: ele não será exibido novamente.'), '/configuracoes/api-tokens');
    }

    public function revogar(string $id): void
    {
        $u = Auth::authorize('api.tokens');
        ApiTokens::revogar($id, (string) $u['id']);
        $this->ok(t('Token revogado. Integrações que o usavam deixam de funcionar imediatamente.'), '/configuracoes/api-tokens');
    }
}
