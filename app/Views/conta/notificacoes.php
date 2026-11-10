<?php
/**
 * @var array<string, mixed>  $usuario
 * @var string                $emailUsuario
 * @var string                $origemConta
 * @var bool                  $emailAtivo
 * @var bool                  $atende
 * @var array<string, bool>   $preferencias
 * @var array<string, string> $eventosSolicitante
 * @var array<string, string> $eventosAtendente
 */
use App\Services\PreferenciasNotificacao;

$usuario = $usuario ?? (usuario() ?? []);
$emailUsuario = isset($emailUsuario) ? trim((string) $emailUsuario) : trim((string) ($usuario['email'] ?? ''));
$origemConta = $origemConta ?? (string) ($usuario['auth_origem'] ?? 'local');
$emailAtivo = $emailAtivo ?? \App\Services\Email::estaAtivo();
$atende = $atende ?? false;
$preferencias = $preferencias ?? PreferenciasNotificacao::padrao();
$eventosSolicitante = $eventosSolicitante ?? PreferenciasNotificacao::EVENTOS_SOLICITANTE;
$eventosAtendente = $eventosAtendente ?? PreferenciasNotificacao::EVENTOS_ATENDENTE;
?>
<div class="conta-notificacoes">
  <?php if (!$emailAtivo): ?>
    <div class="alerta alerta-aviso" role="status">
      <?= icon('alerta', 'icon icon-sm') ?>
      <span><?= e(t('O envio de e-mails do sistema está desligado no momento. Suas preferências serão salvas e passarão a valer assim que o administrador ativar o envio.')) ?></span>
    </div>
  <?php endif; ?>

  <section class="cartao">
    <div class="cartao-cabecalho">
      <h2><?= e(t('Endereço de recebimento')) ?></h2>
      <?= $origemConta === 'ldap' ? badge(t('Active Directory'), 'info') : badge(t('Cadastro local'), 'neutro') ?>
    </div>
    <?php if ($emailUsuario !== ''): ?>
      <p>
        <strong><?= e(t('E-mail cadastrado')) ?>:</strong>
        <code class="mono"><?= e($emailUsuario) ?></code>
      </p>
      <p class="dica">
        <?= e($origemConta === 'ldap'
            ? t('Este endereço é sincronizado automaticamente do Active Directory da Salinas Gold.')
            : t('Para atualizar este endereço de e-mail, solicite a alteração ao Admin/TI.')) ?>
      </p>
    <?php else: ?>
      <div class="alerta alerta-aviso" role="status">
        <?= icon('alerta', 'icon icon-sm') ?>
        <span><?= e(t('Sua conta ainda não possui um endereço de e-mail cadastrado. Procure o Admin/TI para atualizar seu cadastro.')) ?></span>
      </div>
    <?php endif; ?>
  </section>

  <section class="cartao">
    <div class="cartao-cabecalho">
      <h2><?= e(t('Ocasiões em que desejo receber e-mail')) ?></h2>
    </div>
    <p class="dica"><?= e(t('Por padrão, todas as notificações vêm ligadas. Você nunca recebe e-mail sobre ações que você mesmo realizou nem sobre notas internas da equipe.')) ?></p>

    <form method="post" action="<?= e(url('/conta/notificacoes')) ?>" class="form-pilha">
      <?= csrf_field() ?>

      <fieldset class="form-pilha">
        <legend><strong><?= e(t('Como solicitante')) ?></strong></legend>
        <?php foreach ($eventosSolicitante as $chave => $rotulo): ?>
          <label class="checkbox">
            <input type="checkbox" name="eventos[]" value="<?= e($chave) ?>"<?= checked($preferencias[$chave] ?? true) ?>>
            <span><?= e(t($rotulo)) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <?php if ($atende): ?>
        <fieldset class="form-pilha">
          <legend><strong><?= e(t('Como atendente / equipe da área')) ?></strong></legend>
          <?php foreach ($eventosAtendente as $chave => $rotulo): ?>
            <label class="checkbox">
              <input type="checkbox" name="eventos[]" value="<?= e($chave) ?>"<?= checked($preferencias[$chave] ?? true) ?>>
              <span><?= e(t($rotulo)) ?></span>
            </label>
          <?php endforeach; ?>
        </fieldset>
      <?php endif; ?>

      <div class="form-acoes">
        <button type="submit" name="acao" value="salvar" class="btn btn-primario">
          <?= icon('ok', 'icon icon-sm') ?> <?= e(t('Salvar preferências')) ?>
        </button>
        <button type="submit" name="acao" value="desligar_todos" class="btn btn-secundario">
          <?= e(t('Desligar todos')) ?>
        </button>
        <button type="submit" name="acao" value="restaurar_padrao" class="btn btn-fantasma">
          <?= e(t('Restaurar padrão')) ?>
        </button>
      </div>
    </form>
  </section>
</div>
