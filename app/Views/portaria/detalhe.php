<section class="cartao">
  <div class="cartao-cabecalho">
    <h2><?= $r['sentido'] === 'entrada' ? badge('Entrada', 'sucesso') : badge('Saída', 'neutro') ?> <?= e(fmt_data_hora($r['data_hora'])) ?></h2>
    <span class="dica">Registro imutável · ID <?= e($r['id']) ?></span>
  </div>
  <dl class="dados">
    <div><dt>Pessoa</dt><dd><?= e($r['pessoa_nome'] ?? '—') ?> <small class="mudo"><?= e(CATEGORIAS[$r['categoria'] ?? ''] ?? '') ?></small></dd></div>
    <div><dt>Empresa</dt><dd><?= e($r['pessoa_empresa'] ?? '—') ?></dd></div>
    <div><dt>Veículo</dt><dd class="mono"><?= e($r['placa'] ?? '—') ?> <small class="mudo"><?= e($r['marca_modelo'] ?? '') ?></small></dd></div>
    <div><dt>Local</dt><dd><?= e($r['local_nome']) ?></dd></div>
    <div><dt>Destino</dt><dd><?= e($r['destino'] ?? '—') ?></dd></div>
    <div><dt>Responsável interno</dt><dd><?= e($r['responsavel_interno_nome'] ?? '—') ?>
      <small class="mudo"><?= e(trim(($r['responsavel_interno_telefone'] ?? '') . ' ' . ($r['responsavel_interno_email'] ?? ''))) ?></small></dd></div>
    <div><dt>Solicitação</dt><dd><?= $r['sol_nome'] ? e($r['sol_nome']) . ' ' . badge_status_solicitacao($r['sol_status']) : '—' ?></dd></div>
    <div><dt>Porteiro</dt><dd><?= e($r['porteiro_nome'] ?? '—') ?></dd></div>
    <div class="largo"><dt>Observações</dt><dd><?= nl2br(e($r['observacoes'] ?? '—')) ?></dd></div>
  </dl>
</section>

<section class="cartao">
  <div class="cartao-cabecalho"><h2>Fotos / anexos (<?= count($anexos) ?>)</h2></div>
  <?php if (!$anexos): ?>
    <p class="mudo vazio">Nenhuma foto anexada a este registro.</p>
  <?php else: ?>
    <div class="fotos-grade">
      <?php foreach ($anexos as $a): ?>
        <figure class="foto-item">
          <a href="<?= e(url('/anexos/' . $a['id'])) ?>" target="_blank" rel="noopener"><img src="<?= e(url('/anexos/' . $a['id'])) ?>" alt="<?= e(TIPOS_FOTO[$a['tipo_foto']] ?? 'Foto') ?>" loading="lazy"></a>
          <figcaption><?= e(TIPOS_FOTO[$a['tipo_foto']] ?? $a['tipo_foto']) ?> · <?= (int) round(((int) $a['tamanho_bytes']) / 1024) ?> KB</figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<p><a class="btn btn-secundario" href="<?= e(url('/painel')) ?>" data-voltar>Voltar</a></p>
