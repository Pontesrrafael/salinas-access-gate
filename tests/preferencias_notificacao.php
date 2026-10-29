<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\View;
use App\Services\Email;
use App\Services\Notificacoes;
use App\Services\PreferenciasNotificacao;

$checks = 0;
function expect(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
}

Email::usarFilaMemoria([]);
Email::definirAtivo(true);
PreferenciasNotificacao::usarMemoria([]);

try {
    // 1. Catálogo de eventos e padrão (ausência de linha = ligado)
    expect(count(PreferenciasNotificacao::eventosSolicitante()) === 6, 'Deve haver 6 eventos de solicitante.');
    expect(count(PreferenciasNotificacao::eventosAtendente()) === 5, 'Deve haver 5 eventos de atendente/equipe.');
    expect(count(PreferenciasNotificacao::todosEventos()) === 11, 'Total de eventos configuráveis deve ser 11.');

    $padrao = PreferenciasNotificacao::todas('u-solic');
    expect(count($padrao) === 11 && !in_array(false, $padrao, true), 'Ausência de registro deve retornar todos os 11 eventos ligados.');
    foreach (array_keys(PreferenciasNotificacao::todosEventos()) as $ev) {
        expect(PreferenciasNotificacao::quer('u-solic', $ev) === true, "Evento {$ev} deve vir ligado por padrão.");
    }
    expect(PreferenciasNotificacao::quer('', 'solicitante.criado') === false, 'Usuário vazio não deve receber notificação.');
    expect(PreferenciasNotificacao::quer('u-solic', 'evento.inexistente') === true, 'Evento desconhecido continua sendo enviado (preferência só desliga eventos conhecidos).');

    // 2. Salvar preferências individuais, desligar todos e restaurar padrão
    PreferenciasNotificacao::salvar('u-solic', [
        'solicitante.comentario' => false,
        'solicitante.pendente'   => 0,
        'solicitante.criado'     => true,
    ]);
    expect(PreferenciasNotificacao::quer('u-solic', 'solicitante.comentario') === false, 'Evento desmarcado deve ficar desligado.');
    expect(PreferenciasNotificacao::quer('u-solic', 'solicitante.pendente') === false, 'Evento com 0 deve ficar desligado.');
    expect(PreferenciasNotificacao::quer('u-solic', 'solicitante.criado') === true, 'Evento marcado deve permanecer ligado.');
    expect(PreferenciasNotificacao::quer('u-solic', 'solicitante.solucionado') === false, 'Salvar grava o formulário inteiro: evento não marcado (checkbox ausente) fica desligado.');

    PreferenciasNotificacao::desligarTodos('u-solic');
    foreach (array_keys(PreferenciasNotificacao::todosEventos()) as $ev) {
        expect(PreferenciasNotificacao::quer('u-solic', $ev) === false, "Desligar todos deve desativar {$ev}.");
    }

    PreferenciasNotificacao::restaurarPadrao('u-solic');
    foreach (array_keys(PreferenciasNotificacao::todosEventos()) as $ev) {
        expect(PreferenciasNotificacao::quer('u-solic', $ev) === true, "Restaurar padrão deve reativar {$ev}.");
    }

    // 3. Filtragem em Notificacoes::chamado e Notificacoes::montarDestinatarios
    $usuarios = [
        'u-solic' => ['id' => 'u-solic', 'nome' => 'Maria Solicitante', 'email' => 'maria@salinasgold.com.br'],
        'u-atend' => ['id' => 'u-atend', 'nome' => 'João Atendente',   'email' => 'joao@salinasgold.com.br'],
        'u-gest'  => ['id' => 'u-gest',  'nome' => 'Carlos Gestor',    'email' => 'carlos@salinasgold.com.br'],
    ];
    Notificacoes::definirResolvedor(static function (string $tipo, mixed $arg) use ($usuarios): mixed {
        if ($tipo === 'usuario') {
            return $usuarios[(string) $arg] ?? null;
        }
        if ($tipo === 'equipe') {
            return [$usuarios['u-atend'], $usuarios['u-gest']];
        }
        return null;
    });

    $chamadoBase = [
        'id'             => '22222222-2222-4222-8222-222222222222',
        'numero'         => 101,
        'area_id'        => 'e4ef6cc8-aafe-4ad4-80be-a2420a856802',
        'area_nome'      => 'TI',
        'categoria_id'   => '33333333-3333-4333-8333-333333333333',
        'categoria_nome' => 'Suporte',
        'titulo'         => 'Impressora sem rede',
        'solicitante_id' => 'u-solic',
        'atendente_id'   => 'u-atend',
        'status'         => 'novo',
        'prioridade'     => 3,
    ];

    // 3.1. criado (atendente.criado) e recebido (solicitante.criado)
    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-atend', ['atendente.criado' => false]);
    Notificacoes::chamado('criado', $chamadoBase, ['usuario_id' => 'u-solic']);
    $fila = Email::obterFilaMemoria();
    expect(count($fila) === 1 && $fila[0]['para'] === 'carlos@salinasgold.com.br', 'Membro com atendente.criado desligado não recebe e-mail de abertura.');

    Email::usarFilaMemoria([]);
    Notificacoes::chamado('recebido', $chamadoBase, ['usuario_id' => 'u-solic']);
    $fila = Email::obterFilaMemoria();
    expect(count($fila) === 1 && $fila[0]['para'] === 'maria@salinasgold.com.br', 'Solicitante recebe confirmação de chamado recebido quando solicitante.criado está ligado.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-solic', ['solicitante.criado' => false]);
    Notificacoes::chamado('recebido', $chamadoBase, ['usuario_id' => 'u-solic']);
    expect(Email::obterFilaMemoria() === [], 'Solicitante com solicitante.criado desligado não recebe confirmação de abertura.');
    PreferenciasNotificacao::restaurarPadrao('u-solic');
    PreferenciasNotificacao::restaurarPadrao('u-atend');

    // 3.2. atribuido (gestor atribui para u-atend -> notifica u-atend e u-solic)
    Email::usarFilaMemoria([]);
    Notificacoes::chamado('atribuido', $chamadoBase, ['usuario_id' => 'u-gest']);
    expect(count(Email::obterFilaMemoria()) === 2, 'Atribuição por terceiro notifica atendente e solicitante.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-solic', ['solicitante.atribuido' => false]);
    Notificacoes::chamado('atribuido', $chamadoBase, ['usuario_id' => 'u-gest']);
    $fila = Email::obterFilaMemoria();
    expect(count($fila) === 1 && $fila[0]['para'] === 'joao@salinasgold.com.br', 'Desligar solicitante.atribuido filtra apenas o solicitante.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-atend', ['atendente.atribuido' => false]);
    Notificacoes::chamado('atribuido', $chamadoBase, ['usuario_id' => 'u-gest']);
    expect(Email::obterFilaMemoria() === [], 'Desligar ambos os destinatários na atribuição não enfileira e-mail.');
    PreferenciasNotificacao::restaurarPadrao('u-solic');
    PreferenciasNotificacao::restaurarPadrao('u-atend');

    // 3.3. comentario (solicitante.comentario e atendente.comentario, preservando bloqueio de nota interna)
    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-solic', ['solicitante.comentario' => false]);
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-atend', 'tipo' => 'comentario']);
    expect(Email::obterFilaMemoria() === [], 'Solicitante com solicitante.comentario desligado não recebe comentário público.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-atend', ['atendente.comentario' => false]);
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-solic', 'tipo' => 'comentario']);
    expect(Email::obterFilaMemoria() === [], 'Atendente com atendente.comentario desligado não recebe comentário do solicitante.');
    PreferenciasNotificacao::restaurarPadrao('u-solic');
    PreferenciasNotificacao::restaurarPadrao('u-atend');

    Email::usarFilaMemoria([]);
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-atend', 'tipo' => 'interno']);
    expect(Email::obterFilaMemoria() === [], 'Nota interna continua bloqueada mesmo com todas as preferências ligadas.');

    // 3.4. Novos eventos: pendente, transferido, reaberto + solucionado e fechado
    Email::usarFilaMemoria([]);
    Notificacoes::chamado('pendente', $chamadoBase, ['usuario_id' => 'u-atend']);
    expect(count(Email::obterFilaMemoria()) === 1 && Email::obterFilaMemoria()[0]['para'] === 'maria@salinasgold.com.br', 'Evento pendente notifica solicitante por padrão.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-solic', ['solicitante.pendente' => false]);
    Notificacoes::chamado('pendente', $chamadoBase, ['usuario_id' => 'u-atend']);
    expect(Email::obterFilaMemoria() === [], 'Evento pendente respeita solicitante.pendente desligado.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-solic', ['solicitante.solucionado' => false, 'solicitante.fechado' => false]);
    Notificacoes::chamado('solucionado', $chamadoBase, ['usuario_id' => 'u-atend']);
    Notificacoes::chamado('fechado', $chamadoBase, ['automatico' => true]);
    expect(Email::obterFilaMemoria() === [], 'Eventos solucionado e fechado respeitam preferências do solicitante.');
    PreferenciasNotificacao::restaurarPadrao('u-solic');

    Email::usarFilaMemoria([]);
    Notificacoes::chamado('transferido', $chamadoBase, ['usuario_id' => 'u-atend']);
    $fila = Email::obterFilaMemoria();
    expect(count($fila) === 1 && $fila[0]['para'] === 'carlos@salinasgold.com.br', 'Transferência avisa equipe de destino sem notificar quem transferiu.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-gest', ['atendente.transferido' => false]);
    Notificacoes::chamado('transferido', $chamadoBase, ['usuario_id' => 'u-atend']);
    expect(Email::obterFilaMemoria() === [], 'Transferência respeita atendente.transferido desligado.');
    PreferenciasNotificacao::restaurarPadrao('u-gest');

    Email::usarFilaMemoria([]);
    Notificacoes::chamado('reaberto', $chamadoBase, ['usuario_id' => 'u-solic']);
    $fila = Email::obterFilaMemoria();
    expect(count($fila) === 1 && $fila[0]['para'] === 'joao@salinasgold.com.br', 'Reabertura pelo solicitante avisa o atendente responsável.');

    Email::usarFilaMemoria([]);
    PreferenciasNotificacao::salvar('u-atend', ['atendente.reaberto' => false]);
    Notificacoes::chamado('reaberto', $chamadoBase, ['usuario_id' => 'u-solic']);
    expect(Email::obterFilaMemoria() === [], 'Reabertura respeita atendente.reaberto desligado.');
    PreferenciasNotificacao::restaurarPadrao('u-atend');

    // 4. Renderização da view conta/notificacoes (solicitante comum vs atendente/Admin, aviso de e-mail desligado e CSP)
    $dadosView = [
        'usuario'            => ['id' => 'u-solic', 'nome' => 'Maria Solicitante', 'email' => 'maria@salinasgold.com.br', 'auth_origem' => 'ldap'],
        'atende'             => false,
        'emailAtivo'         => false,
        'preferencias'       => PreferenciasNotificacao::todas('u-solic'),
        'eventosSolicitante' => PreferenciasNotificacao::eventosSolicitante(),
        'eventosAtendente'   => PreferenciasNotificacao::eventosAtendente(),
    ];
    $htmlSolicitante = View::partial('conta/notificacoes', $dadosView);
    expect(str_contains($htmlSolicitante, 'maria@salinasgold.com.br'), 'Tela exibe o e-mail do usuário.');
    expect(str_contains($htmlSolicitante, 'O envio de e-mails do sistema está desligado no momento'), 'Tela exibe aviso quando o envio global está desligado.');
    expect(str_contains($htmlSolicitante, 'value="solicitante.criado"') && !str_contains($htmlSolicitante, 'value="atendente.criado"'), 'Solicitante comum vê apenas o grupo Como solicitante.');
    expect(str_contains($htmlSolicitante, 'value="desligar_todos"') && str_contains($htmlSolicitante, 'value="restaurar_padrao"'), 'Tela oferece botões de desligar todos e restaurar padrão.');
    expect(!preg_match('/<(script|style)\b|\s(?:style|onload|onclick|onchange)=/i', $htmlSolicitante), 'Tela de preferências respeita a CSP.');

    $dadosView['atende'] = true;
    $dadosView['emailAtivo'] = true;
    $htmlAtendente = View::partial('conta/notificacoes', $dadosView);
    expect(!str_contains($htmlAtendente, 'O envio de e-mails do sistema está desligado no momento'), 'Aviso de e-mail desligado some quando o envio está ativo.');
    expect(str_contains($htmlAtendente, 'value="solicitante.criado"') && str_contains($htmlAtendente, 'value="atendente.criado"') && str_contains($htmlAtendente, 'value="atendente.reaberto"'), 'Atendente ou Admin vê os dois grupos de eventos.');
} finally {
    PreferenciasNotificacao::usarMemoria(null);
    PreferenciasNotificacao::definirResolvedor(null);
    Notificacoes::definirResolvedor(null);
    Email::usarFilaMemoria(null);
    Email::definirAtivo(null);
}

echo "Preferências de notificação: {$checks} verificações sem banco OK.\n";
