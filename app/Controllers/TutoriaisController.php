<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Services\Tutoriais;

final class TutoriaisController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $this->view('tutoriais/index', ['titulo' => 'Tutoriais', 'descricao' => 'Ajuda permanente para a rotina do Access Gate.', 'modulos' => Tutoriais::permitidos()]);
    }
}
