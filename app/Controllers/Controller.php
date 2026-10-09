<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http;
use App\Core\Session;
use App\Core\Uuid;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        View::render($template, [
            'flash' => Session::pullFlash(),
            'old'   => Session::pullOld(),
            ...$data,
        ], $layout);
    }

    /** Retorna ao formulário com erro e dados preenchidos. */
    protected function falha(string $mensagem, string $voltarPara): never
    {
        Session::flash('erro', $mensagem);
        Session::flashOld($_POST);
        Http::redirect($voltarPara);
    }

    protected function ok(string $mensagem, string $para, array $query = []): never
    {
        Session::flash('sucesso', $mensagem);
        Http::redirect($para, $query);
    }

    protected function uuidOu404(string $id): string
    {
        if (!Uuid::valid($id)) {
            Http::abort(404, 'Registro não encontrado.');
        }
        return $id;
    }

    /** Paginação simples: [página, limite, offset] */
    protected function paginacao(int $limite = 50): array
    {
        $pagina = max(1, (int) Http::input('pagina', 1));
        return [$pagina, $limite, ($pagina - 1) * $limite];
    }
}
