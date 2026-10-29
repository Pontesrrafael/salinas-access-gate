<?php
use App\Controllers\ServicosConfigController;
use App\Services\Chamados;
use App\Services\FormularioDinamico;
slot('acoes', '<a class="btn btn-secundario" href="' . e(url('/chamados/meus')) . '">' . icon('solicit') . e(t('Meus chamados')) . '</a>');
?>
<div class="chamados">
<section class="cartao" data-tour="filtros">
  <div class="cartao-cabecalho"><h2><?= icon('local') ?> <?= e(t('Área e categoria')) ?></h2></div>
  <p class="dica"><?= e(t('Escolha a área e a categoria do serviço. O formulário é carregado automaticamente.')) ?></p>
  <form method="get" action="<?= e(url('/chamados/abrir')) ?>" class="form-pilha">
    <div class="grade-2">
      <label class="campo"><span><?= e(t('Área de atendimento')) ?></span>
        <select name="area_id" required data-autosubmit="1" data-limpar-alvo="categoria_id"><option value=""><?= e(t('Selecione')) ?></option>
        <?php foreach ($areas as $area): ?><option value="<?= e($area['id']) ?>"<?= selected($areaId, $area['id']) ?>><?= e($area['nome']) ?></option><?php endforeach; ?></select>
      </label>
      <label class="campo"><span><?= e(t('Categoria do serviço')) ?></span>
        <select name="categoria_id" data-autosubmit="1"><option value=""><?= e(t($areaId ? 'Selecione' : 'Selecione primeiro a área')) ?></option>
        <?php foreach ($categorias as $cat): ?><option value="<?= e($cat['id']) ?>"<?= selected($categoriaId, $cat['id']) ?>><?= e($cat['caminho']) ?></option><?php endforeach; ?></select>
      </label>
    </div>
    <div class="form-acoes"><button class="btn btn-secundario" type="submit"><?= icon('atualizar') ?> <?= e(t('Carregar formulário')) ?></button></div>
  </form>
  <?php if (!$areas || ($areaId && !$categorias)): ?><p class="alerta alerta-info"><?= e(t('Nenhum serviço disponível. Solicite ao Admin/TI o cadastro das categorias da área.')) ?></p><?php endif; ?>
</section>
<?php if ($categoria): ?>
<form method="post" action="<?= e(url('/chamados')) ?>" enctype="multipart/form-data" class="cartao form-pilha">
  <?= csrf_field() ?>
  <input type="hidden" name="area_id" value="<?= e($areaId) ?>"><input type="hidden" name="categoria_id" value="<?= e($categoriaId) ?>">
  <div class="cartao-cabecalho"><h2><?= icon('solicit') ?> <?= e($categoria['nome']) ?></h2></div>
  <?php if ($categoria['descricao']): ?><p class="chamado-texto"><?= e($categoria['descricao']) ?></p><?php endif; ?>
  <label class="campo"><span><?= e(t('Título')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span><input name="titulo" type="text" required maxlength="200" value="<?= e(old($old, 'titulo')) ?>"></label>
  <label class="campo"><span><?= e(t('Descrição')) ?></span><textarea name="descricao" rows="5" maxlength="20000"><?= e(old($old, 'descricao')) ?></textarea></label>
  <div class="grade-2">
    <label class="campo"><span><?= e(t('O atendimento é para você?')) ?></span><select name="para_voce" data-para-voce="1"><option value="1"<?= selected(old($old, 'para_voce', '1'), '1') ?>><?= e(t('Sim')) ?></option><option value="0"<?= selected(old($old, 'para_voce', '1'), '0') ?>><?= e(t('Não')) ?></option></select></label>
    <label class="campo" id="campo-beneficiario" data-beneficiario-campo="1"><span><?= e(t('Nome de quem receberá o atendimento')) ?></span><input name="beneficiario_nome" type="text" maxlength="150" value="<?= e(old($old, 'beneficiario_nome')) ?>"><small><?= e(t('Preencha somente se o atendimento for para outra pessoa.')) ?></small></label>
    <label class="campo"><span><?= e(t('Urgência')) ?></span><select name="urgencia" required>
      <?php foreach (Chamados::URGENCIAS as $valor => $rotulo): ?><option value="<?= $valor ?>"<?= selected(old($old, 'urgencia', $categoria['urgencia_padrao']), $valor) ?>><?= e(t($rotulo)) ?></option><?php endforeach; ?>
    </select></label>
    <label class="campo"><span><?= e(t('Impacto')) ?></span><select name="impacto" required>
      <?php foreach (Chamados::IMPACTOS as $valor => $rotulo): ?><option value="<?= $valor ?>"<?= selected(old($old, 'impacto', 1), $valor) ?>><?= e(t($rotulo)) ?></option><?php endforeach; ?>
    </select></label>
  </div>
  <details class="ajuda-urgencia" open><summary><?= e(t('Como escolher a urgência')) ?></summary><ul class="passos">
    <?php foreach (Chamados::URGENCIAS as $valor => $rotulo): ?><li><strong><?= e(t($rotulo)) ?>:</strong> <?= e(t(Chamados::EXPLICACOES[$valor])) ?></li><?php endforeach; ?>
  </ul></details>
  <label class="checkbox"><input type="checkbox" name="recorrente" value="1"<?= checked(old($old, 'recorrente')) ?>> <?= e(t('Este problema é recorrente')) ?></label>
  <?= FormularioDinamico::renderizar($campos, $old) ?>
  <label class="campo"><span><?= e(t('Anexos')) ?></span><input name="anexos[]" type="file" multiple accept="application/pdf,image/jpeg,image/png,image/webp"><small><?= e(t('PDF, JPG, PNG ou WEBP, até 4 MB por arquivo.')) ?></small></label>
  <?php
    $sla = $sla ?? Chamados::slaEfetivo($categoria, []);
    $slaRespFmt = $sla['resposta_min'] !== null ? ServicosConfigController::formatarSla($sla['resposta_min']) : null;
    $slaSolFmt = $sla['solucao_min'] !== null ? ServicosConfigController::formatarSla($sla['solucao_min']) : null;
  ?>
  <p class="dica">
    <?= icon('relogio') ?>
    <?= e($textoSla ?? Chamados::textoSla($categoria)) ?>
    <?php if ($slaRespFmt !== null || $slaSolFmt !== null): ?>
      <?= e(t('Prazo de resposta')) ?>: <strong><?= e($slaRespFmt ?? t('Sem SLA definido')) ?></strong> ·
      <?= e(t('Prazo de solução')) ?>: <strong><?= e($slaSolFmt ?? t('Sem SLA definido')) ?></strong>.
    <?php endif; ?>
    <?= e(t('O prazo de solução pausa enquanto o chamado estiver pendente.')) ?>
  </p>
  <div class="form-acoes"><button class="btn btn-ouro" type="submit"><?= icon('mais') ?> <?= e(t('Abrir chamado')) ?></button></div>
</form>
<?php endif; ?>
</div>
