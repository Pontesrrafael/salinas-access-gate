<?php /** @var ?array $s @var ?string $token */ ?>
<?php if (!$s): ?>
  <h1 class="titulo-centro">Convite indisponível</h1>
  <p class="centro">Este convite não existe, expirou ou a visita não está mais aprovada. Procure o responsável pela sua visita.</p>
<?php else: ?>
  <h1 class="titulo-centro">Convite de acesso</h1>
  <div class="convite-qr centro">
    <canvas data-qr="<?= e($token) ?>" width="264" height="264" aria-label="QR do convite"></canvas>
  </div>
  <dl class="dados convite-publico">
    <div><dt>Visitante</dt><dd><strong><?= e($s['visitante_nome']) ?></strong></dd></div>
    <div><dt>Data</dt><dd><strong><?= e(fmt_data($s['data_prevista'])) ?></strong></dd></div>
    <div><dt>Local</dt><dd><?= e($s['local_nome'] ?? 'Portaria') ?></dd></div>
  </dl>
  <p class="dica centro">Mostre este QR na portaria, com um documento com foto. Aumente o brilho da tela para facilitar a leitura.</p>
<?php endif; ?>
