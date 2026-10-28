<div class="grade-portaria">
  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad"><h2><?= icon('relogio', 'icon icon-sm') ?> <?= e(t('Aguardando verificação')) ?> <?= $fila ? badge((string) count($fila), 'ouro') : '' ?></h2></div>
    <?php if (!$fila): ?>
      <p class="mudo vazio"><?= e(t('Nenhuma solicitação aguardando SSMA.')) ?></p>
    <?php else: ?>
      <ul class="lista-simples pad-lista">
        <?php foreach ($fila as $s): ?>
          <li>
            <span>
              <strong><?= e($s['visitante_nome']) ?></strong>
              <span class="sub"><?= e(($s['empresa'] ?? t('Sem empresa')) . ' · ' . ($s['area_nome'] ?? t('Sem área')) . ' · prevista ' . fmt_data($s['data_prevista'])) ?></span>
              <?php if ($s['motivo']): ?><span class="sub"><?= e(mb_strimwidth($s['motivo'], 0, 140, '…')) ?></span><?php endif; ?>
            </span>
            <a class="btn btn-ouro btn-sm" href="<?= e(url('/ssma/' . $s['id'])) ?>"><?= e(t('Verificar')) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad"><h2><?= e(t('Histórico recente')) ?></h2></div>
    <?php if (!$historico): ?>
      <p class="mudo vazio"><?= e(t('Sem registros.')) ?></p>
    <?php else: ?>
      <ul class="lista-simples pad-lista">
        <?php foreach ($historico as $h): ?>
          <li>
            <span><strong><?= e($h['visitante_nome']) ?></strong>
              <span class="sub"><?= e(($h['empresa'] ?? '') . ' · ' . fmt_data_hora($h['created_at'])) ?></span>
              <?php if ($h['verificado_por_nome']): ?><span class="sub"><?= e(t('por')) ?> <?= e($h['verificado_por_nome']) ?></span><?php endif; ?>
              <?php if ($h['observacao']): ?><span class="sub"><?= e($h['observacao']) ?></span><?php endif; ?>
            </span>
            <?= $h['status'] === 'apto' ? badge(t('Apto'), 'sucesso') : badge(t('Não apto'), 'perigo') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
