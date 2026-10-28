<?php
$novo = $p === null;
$podeEditar = $novo ? can('pessoas.criar') : can('pessoas.editar');
$dis = $podeEditar ? '' : ' disabled';
$v = static fn (string $k) => old($old, $k, $p[$k] ?? '');
$acao = $novo ? url('/pessoas') : url('/pessoas/' . $p['id']);
?>
<form method="post" action="<?= e($acao) ?>" data-tour="formulario">
  <?= csrf_field() ?>
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= e(t('Identificação')) ?></h2>
      <?php if (!$novo && $p['usuario']): ?><?= badge(t('Usuário do sistema:') . ' ' . $p['usuario']['email'], 'info') ?><?php endif; ?></div>
    <div class="grade-3">
      <label class="campo campo-largo"><span><?= e(t('Nome completo')) ?> <span class="obrigatorio">*</span></span><input name="nome" required maxlength="200" value="<?= e($v('nome')) ?>"<?= $dis ?>></label>
      <?php if ($novo || can('pessoas.ver_cpf')): ?>
        <label class="campo"><span><?= e(t('CPF')) ?></span><input name="cpf" maxlength="14" inputmode="numeric" data-mascara="cpf" value="<?= e($novo ? old($old, 'cpf') : ($p['cpf_exibicao'] ?? '')) ?>"<?= $dis ?>>
          <?php if (!$novo): ?><small><?= e(t('Visível para Admin/TI e Supervisor. Alterações ficam auditadas.')) ?></small><?php endif; ?></label>
      <?php else: ?>
        <div class="campo"><span><?= e(t('CPF')) ?></span><input value="<?= e($p['cpf_exibicao'] ?? t('Não informado')) ?>" disabled><small><?= e(t('Mascarado conforme LGPD.')) ?></small></div>
      <?php endif; ?>
      <label class="campo"><span><?= e(t('RG / outro documento')) ?></span><input name="rg" maxlength="30" value="<?= e($v('rg')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Matrícula')) ?></span><input name="matricula" maxlength="50" value="<?= e($v('matricula')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Categoria')) ?> <span class="obrigatorio">*</span></span>
        <select name="categoria" required<?= $dis ?>><option value=""><?= e(t('Selecione')) ?></option>
          <?php foreach (CATEGORIAS as $k => $rot): ?><option value="<?= e($k) ?>"<?= selected($v('categoria'), $k) ?>><?= e(t($rot)) ?></option><?php endforeach; ?>
        </select></label>
      <label class="campo"><span><?= e(t('Empresa')) ?></span><input name="empresa" maxlength="150" value="<?= e($v('empresa')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Função')) ?></span><input name="funcao" maxlength="120" value="<?= e($v('funcao')) ?>"<?= $dis ?>></label>
    </div>
  </section>

  <section class="cartao">
    <h2><?= e(t('Contato')) ?></h2>
    <div class="grade-3">
      <label class="campo"><span><?= e(t('Telefone principal')) ?></span><input name="telefone_principal" maxlength="30" data-mascara="telefone" value="<?= e($v('telefone_principal')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('WhatsApp')) ?></span><input name="whatsapp" maxlength="30" data-mascara="telefone" value="<?= e($v('whatsapp')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('E-mail principal')) ?></span><input type="email" name="email_principal" maxlength="190" value="<?= e($v('email_principal')) ?>"<?= $dis ?>></label>
      <label class="campo campo-largo"><span><?= e(t('Observações de contato')) ?></span><textarea name="observacoes_contato" rows="2" maxlength="2000"<?= $dis ?>><?= e($v('observacoes_contato')) ?></textarea></label>
    </div>
  </section>

  <section class="cartao">
    <h2><?= e(t('Padrões da portaria')) ?></h2>
    <p class="dica"><?= e(t('Preenchem automaticamente os detalhes na tela da portaria quando esta pessoa é selecionada.')) ?></p>
    <div class="grade-2 compacta">
      <label class="campo"><span><?= e(t('Destino padrão')) ?></span><input name="destino_padrao" maxlength="150" value="<?= e($v('destino_padrao')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Responsável interno padrão')) ?></span><input name="responsavel_interno_padrao" maxlength="150" value="<?= e($v('responsavel_interno_padrao')) ?>"<?= $dis ?>></label>
      <label class="campo campo-largo"><span><?= e(t('Observações padrão da portaria')) ?></span><textarea name="observacoes_portaria_padrao" rows="2" maxlength="2000"<?= $dis ?>><?= e($v('observacoes_portaria_padrao')) ?></textarea></label>
      <label class="campo campo-largo"><span><?= e(t('Observações gerais')) ?></span><textarea name="observacoes" rows="2" maxlength="2000"<?= $dis ?>><?= e($v('observacoes')) ?></textarea></label>
      <?php if (!$novo): ?>
        <label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($p['ativo']) ?><?= $dis ?>> <?= e(t('Cadastro ativo (inativos não aparecem na portaria)')) ?></label>
      <?php endif; ?>
    </div>
  </section>

  <div class="form-acoes">
    <a class="btn btn-secundario" href="<?= e(url('/pessoas')) ?>"><?= e(t('Voltar')) ?></a>
    <?php if ($podeEditar): ?><button class="btn btn-primario" type="submit"><?= $novo ? t('Cadastrar') : t('Salvar alterações') ?></button><?php endif; ?>
  </div>
</form>

<?php if (!$novo): ?>
<section class="cartao" id="documentos">
  <div class="cartao-cabecalho"><h2><?= e(t('Documentos (ASO, NRs, Integração, CNH)')) ?></h2></div>
  <?php if (!$docs): ?>
    <p class="dica"><?= e(t('Nenhum documento cadastrado. Terceiros com documento vencido são bloqueados na portaria.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Tipo')) ?></th><th><?= e(t('Número')) ?></th><th><?= e(t('Emissão')) ?></th><th><?= e(t('Validade')) ?></th><th><?= e(t('Situação')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): $st = status_documento($d['validade']); ?>
        <tr>
          <td><?= e(label_t(TIPOS_DOC, $d['tipo'], $d['tipo'])) ?></td>
          <td><?= e($d['numero'] ?? '—') ?></td>
          <td><?= e(fmt_data($d['emissao'])) ?></td>
          <td><?= e(fmt_data($d['validade'])) ?></td>
          <td><?= badge(['vencido' => t('Vencido'), 'proximo_vencimento' => t('Vence em até 30 dias'), 'valido' => t('Válido')][$st], $st === 'vencido' ? 'perigo' : ($st === 'proximo_vencimento' ? 'aviso' : 'sucesso')) ?></td>
          <td class="acoes">
            <?php if (can('documentos.excluir')): ?>
              <form method="post" action="<?= e(url('/pessoas/' . $p['id'] . '/documentos/' . $d['id'] . '/excluir')) ?>" data-confirmar="<?= e(t('Remover este documento?')) ?>">
                <?= csrf_field() ?><button class="btn btn-fantasma btn-sm" type="submit"><?= icon('lixo', 'icon icon-sm') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if (can('documentos.gerir')): ?>
    <form method="post" action="<?= e(url('/pessoas/' . $p['id'] . '/documentos')) ?>" class="filtros form-documento">
      <?= csrf_field() ?>
      <label class="campo"><span><?= e(t('Tipo')) ?></span><select name="tipo" required><?php foreach (TIPOS_DOC as $k => $rot): ?><option value="<?= e($k) ?>"><?= e(t($rot)) ?></option><?php endforeach; ?></select></label>
      <label class="campo"><span><?= e(t('Número')) ?></span><input name="numero" maxlength="60"></label>
      <label class="campo"><span><?= e(t('Emissão')) ?></span><input type="date" name="emissao"></label>
      <label class="campo"><span><?= e(t('Validade')) ?> <span class="obrigatorio">*</span></span><input type="date" name="validade" required></label>
      <button class="btn btn-secundario" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar')) ?></button>
    </form>
  <?php endif; ?>
</section>

<?php if (can('pessoas.vincular_usuario')): ?>
<section class="cartao">
  <h2><?= e(t('Vínculo com usuário do sistema')) ?></h2>
  <p class="dica"><?= e(t('Pessoa vinculada é sugerida como responsável interno quando o usuário opera a portaria.')) ?></p>
  <form method="post" action="<?= e(url('/pessoas/' . $p['id'] . '/vinculo')) ?>" class="filtros">
    <?= csrf_field() ?>
    <label class="campo"><span><?= e(t('Usuário')) ?></span>
      <select name="usuario_id">
        <option value=""><?= e(t('— Sem vínculo —')) ?></option>
        <?php if ($p['usuario']): ?><option value="<?= e($p['usuario']['id']) ?>" selected><?= e($p['usuario']['nome'] . ' (' . $p['usuario']['email'] . ')') ?></option><?php endif; ?>
        <?php foreach ($usuariosLivres as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['nome'] . ' (' . $u['email'] . ')') ?></option><?php endforeach; ?>
      </select></label>
    <button class="btn btn-secundario" type="submit"><?= e(t('Salvar vínculo')) ?></button>
  </form>
</section>
<?php endif; ?>

<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2><?= e(t('Últimos acessos')) ?></h2></div>
  <?php if (empty($acessos)): ?>
    <p class="mudo vazio"><?= e(t('Nenhum acesso registrado.')) ?></p>
  <?php else: ?>
    <table class="tabela">
      <tbody>
      <?php foreach ($acessos as $a): ?>
        <tr><td class="nowrap"><a href="<?= e(url('/registros/' . $a['id'])) ?>"><?= e(fmt_data_hora($a['data_hora'])) ?></a></td>
          <td><?= $a['sentido'] === 'entrada' ? badge(t('Entrada'), 'sucesso') : badge(t('Saída'), 'neutro') ?></td>
          <td><?= e($a['local_nome']) ?></td><td class="mono"><?= e($a['placa'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if (can('lgpd.titular')): ?>
<section class="cartao">
  <h2><?= e(t('Direitos do titular (LGPD)')) ?></h2>
  <p class="dica"><?= e(t('Gera um arquivo com todos os dados desta pessoa (cadastro, documentos, acessos, solicitações e SSMA) para atender pedido de acesso do titular. A geração fica na auditoria.')) ?></p>
  <form method="post" action="<?= e(url('/pessoas/' . $p['id'] . '/dados-titular')) ?>" class="filtros">
    <?= csrf_field() ?>
    <label class="campo"><span><?= e(t('Protocolo do pedido')) ?></span><input name="protocolo" maxlength="100" placeholder="<?= e(t('ex.: LGPD-2026-014')) ?>"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('download', 'icon icon-sm') ?> <?= e(t('Exportar dados do titular')) ?></button>
  </form>
</section>
<?php endif; ?>

<?php if (can('pessoas.excluir')): ?>
<section class="cartao zona-perigo">
  <h2><?= e(t('Excluir cadastro')) ?></h2>
  <p class="dica"><?= e(t('Só é possível excluir pessoas sem histórico de acesso ou SSMA. Para as demais, desmarque “Cadastro ativo”.')) ?></p>
  <form method="post" action="<?= e(url('/pessoas/' . $p['id'] . '/excluir')) ?>" data-confirmar="Excluir definitivamente o cadastro de <?= e($p['nome']) ?>?">
    <?= csrf_field() ?><button class="btn btn-perigo" type="submit"><?= icon('lixo', 'icon icon-sm') ?> <?= e(t('Excluir pessoa')) ?></button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
