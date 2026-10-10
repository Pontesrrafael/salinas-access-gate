<?php
/** @var array $s @var string $token @var string $linkExterno @var bool $vigente */
slot('acoes', '<button type="button" class="btn btn-secundario" data-imprimir>' . icon('imprimir') . ' Imprimir</button>');
$texto = 'Convite de acesso — Salinas Gold Mineração. ' . $s['visitante_nome'] . ', sua visita em ' . fmt_data($s['data_prevista'])
       . ($s['local_nome'] ? ' (' . $s['local_nome'] . ')' : '') . ' foi aprovada. Apresente este QR na portaria: ' . $linkExterno;
?>
<?php if (!$vigente): ?>
  <div class="alerta alerta-aviso">A data deste convite (<?= e(fmt_data($s['data_prevista'])) ?>) já passou. O QR não libera mais a entrada.</div>
<?php endif; ?>
<section class="cartao convite">
  <div class="convite-qr">
    <canvas data-qr="<?= e($token) ?>" width="264" height="264" aria-label="QR do convite de <?= e($s['visitante_nome']) ?>"></canvas>
    <p class="mono convite-codigo"><?= e(substr($token, 5, 8)) ?></p>
  </div>
  <div class="convite-dados">
    <h2><?= e($s['visitante_nome']) ?></h2>
    <dl class="dados">
      <div><dt>Data da visita</dt><dd><strong><?= e(fmt_data($s['data_prevista'])) ?></strong></dd></div>
      <div><dt>Local</dt><dd><?= e($s['local_nome'] ?? 'Portaria') ?></dd></div>
      <div><dt>Empresa</dt><dd><?= e($s['empresa'] ?: '—') ?></dd></div>
      <div><dt>Área</dt><dd><?= e($s['area_nome'] ?? '—') ?></dd></div>
      <div><dt>Responsável</dt><dd><?= e($s['responsavel_nome'] ?? '—') ?></dd></div>
      <div><dt>Tipo</dt><dd><?= $s['tipo'] === 'terceiro' ? 'Terceiro' : 'Visitante' ?><?= (int) $s['atividade_risco'] === 1 ? ' · ' . badge('Atividade de risco', 'perigo') : '' ?></dd></div>
    </dl>
    <p class="dica">Apresente este QR na portaria junto com um documento com foto. O QR vale somente na data da visita.</p>
  </div>
</section>
<section class="cartao nao-imprimir">
  <h2>Enviar ao visitante</h2>
  <div class="form-acoes esquerda">
    <button type="button" class="btn btn-primario" data-copiar="<?= e($linkExterno) ?>"><?= icon('link', 'icon icon-sm') ?> Copiar link do convite</button>
    <a class="btn btn-secundario" href="https://wa.me/?text=<?= e(rawurlencode($texto)) ?>" target="_blank" rel="noopener noreferrer">Enviar pelo WhatsApp</a>
    <a class="btn btn-fantasma" href="<?= e(url('/solicitacoes', ['aba' => 'hoje'])) ?>">Voltar</a>
  </div>
  <p class="dica">O link abre só o convite (nome, data e local), sem login. Ele deixa de funcionar após a data da visita ou se a solicitação deixar de estar aprovada.</p>
</section>
