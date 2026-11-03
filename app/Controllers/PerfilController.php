<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Csrf, Database, Http, Session, Uuid};

final class PerfilController extends Controller
{
    /** POST /conta/perfil; o gerente registra a rota na integração. */
    public function trocar(): void
    {
        Csrf::verify();
        Auth::requireLogin();
        if (!Auth::trocarPerfil(Http::str('perfil'))) {
            Http::abort(403, t('Você não possui este perfil.'));
        }
        // Reaplica senha obrigatória e MFA também no contexto recém-selecionado.
        Auth::requireLogin();
        Session::flash('sucesso', t('Perfil alterado.'));
        Http::redirect(self::destino(Http::str('voltar')));
    }

    /** Só preserva páginas GET conhecidas e autorizadas; evita retorno externo e efeitos por GET. */
    public static function destino(string $voltar): string
    {
        if (!str_starts_with($voltar, '/') || str_starts_with($voltar, '//')
            || preg_match('/[\\\\\x00-\x20\x7f]/', $voltar)) {
            return Auth::inicioPerfil();
        }
        $path = parse_url($voltar, PHP_URL_PATH);
        if (!is_string($path) || str_contains($path, '%')) { return Auth::inicioPerfil(); }
        $permissao = match ($path) {
            '/painel' => 'painel.ver', '/indicadores' => 'indicadores.ver',
            '/portaria' => 'portaria.operar', '/solicitacoes' => 'solicitacoes.ver',
            '/ssma' => 'ssma.verificar', '/presenca' => 'registros.ver',
            '/pessoas', '/pessoas/nova' => $path === '/pessoas' ? 'pessoas.ver' : 'pessoas.criar',
            '/veiculos', '/veiculos/novo' => $path === '/veiculos' ? 'veiculos.ver' : 'veiculos.criar',
            '/infleet', '/infleet/percursos' => 'infleet.ver', '/locais' => 'locais.gerir',
            '/relatorios' => 'relatorios.ver', '/auditoria' => 'auditoria.ver',
            '/usuarios', '/usuarios/novo' => 'usuarios.gerir', '/configuracoes' => 'configuracoes.gerir',
            '/chamados/abrir', '/chamados/meus' => 'chamados.abrir', '/chamados/fila' => 'chamados.atender',
            '/servicos/config' => 'chamados.configurar_area',
            default => null,
        };
        if (in_array($path, ['/conta/notificacoes', '/conta/mfa', '/trocar-senha', '/tutoriais'], true)
            || ($permissao !== null && Auth::can($permissao))) {
            // Filtros de outras áreas não acompanham a troca para o gestor.
            return Auth::is('gestor_area') && in_array($path, ['/chamados/fila', '/servicos/config'], true) ? $path : $voltar;
        }
        if (is_string($path) && preg_match('#^/(usuarios|pessoas|veiculos|ssma|registros|chamados)/([a-f0-9-]+)$#i', $path, $m)
            && Uuid::valid($m[2])) {
            if ($m[1] === 'chamados') {
                $c = Database::one('SELECT solicitante_id, area_id FROM chamados WHERE id = ?', [$m[2]]);
                if ($c && (Auth::is('admin') || $c['solicitante_id'] === Auth::id()
                    || (Auth::can('chamados.atender') && (Auth::is('gestor_area') ? Auth::gereArea($c['area_id'])
                        : (bool) Database::value('SELECT 1 FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?', [$c['area_id'], Auth::id()]))))) {
                    return $voltar;
                }
            } else {
                $permissao = ['usuarios' => 'usuarios.gerir', 'pessoas' => 'pessoas.ver', 'veiculos' => 'veiculos.ver',
                    'ssma' => 'ssma.verificar', 'registros' => 'registros.ver'][$m[1]];
                if (Auth::can($permissao)) { return $voltar; }
            }
        }
        return Auth::inicioPerfil();
    }
}
