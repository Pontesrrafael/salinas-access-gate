<section class="cartao">
  <h2>Sincronização da frota</h2>
  <?php if (!$habilitada): ?>
    <p class="alerta alerta-aviso">Integração desativada. O Admin/TI deve configurar o token e a organização Infleet no servidor.</p>
  <?php endif; ?>
  <p class="dica">Novos veículos e pessoas são cadastrados inativos para conferência. Autorizações, documentos e regras de acesso continuam sob controle da portaria.</p>
  <p>Última sincronização: <strong><?= $ultima ? e(fmt_data_hora($ultima['concluido_em'])) : 'Ainda não realizada' ?></strong></p>
  <?php if ($ultima): ?><p class="dica"><?= e($ultima['veiculos']) ?> veículos · <?= e($ultima['motoristas']) ?> motoristas · <?= e($ultima['pendencias']) ?> vínculos pendentes na última execução.</p><?php endif; ?>
  <?php if (can('infleet.gerir') && $habilitada): ?>
    <form method="post" action="<?= e(url('/infleet/sincronizar')) ?>" data-confirmar="Sincronizar os cadastros da Infleet? Novos registros ficarão inativos para conferência.">
      <?= csrf_field() ?><button class="btn btn-primario" type="submit">Sincronizar agora</button>
    </form>
  <?php endif; ?>
</section>
<section class="cartao sem-padding">
  <div class="tabela-rolagem"><table class="tabela">
    <caption>Veículos Infleet · Cor mantida no cadastro local · Odômetro na unidade fornecida pela API</caption>
    <thead><tr><th>Placa</th><th>Modelo</th><th>Cor local</th><th>Odômetro</th><th>Conferência</th><th>Atualizado em</th><th>Percursos</th></tr></thead>
    <tbody><?php foreach ($veiculos as $v): ?><tr>
      <td class="mono"><?= e($v['placa']) ?><?php if (!(int) $v['disponivel']): ?><span class="sub">Ausente na última sincronização</span><?php endif; ?></td><td><?= e($v['marca_modelo'] ?? '—') ?></td><td><?= e($v['cor'] ?? '—') ?></td>
      <td><?= $v['odometro'] !== null ? e(number_format((float) $v['odometro'], 3, ',', '.')) : 'Sem leitura' ?></td>
      <td><?php if ($v['veiculo_id']): ?><a href="<?= e(url('/veiculos/' . $v['veiculo_id'])) ?>"><?= (int) $v['ativo_local'] ? 'Cadastro vinculado' : 'Conferir cadastro inativo' ?></a><?php else: ?>Placa, tipo ou vínculo pendente<?php endif; ?><?= !(int) $v['ativo'] ? ' · Inativo na Infleet' : '' ?></td>
      <td><?= e(fmt_data_hora($v['sincronizado_em'])) ?></td>
      <td><a class="btn btn-secundario btn-sm" href="<?= e(url('/infleet/percursos', ['veiculo' => $v['id']])) ?>">Ver percursos</a></td>
    </tr><?php endforeach; ?>
    <?php if (!$veiculos): ?><tr><td colspan="7">Nenhum veículo sincronizado.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
<section class="cartao sem-padding">
  <div class="tabela-rolagem"><table class="tabela">
    <caption>Motoristas Infleet</caption>
    <thead><tr><th>Nome</th><th>CPF</th><th>Situação na Infleet</th><th>Cadastro da portaria</th><th>Atualizado em</th></tr></thead>
    <tbody><?php foreach ($motoristas as $m): ?><tr>
      <td><?= e($m['nome']) ?><?php if (!(int) $m['disponivel']): ?><span class="sub">Ausente na última sincronização</span><?php endif; ?></td><td class="mono"><?= e($m['cpf_mascarado'] ?? 'Ausente ou inválido') ?></td><td><?= (int) $m['ativo'] ? 'Ativo' : 'Inativo' ?></td>
      <td><?php if ($m['pessoa_id']): ?><a href="<?= e(url('/pessoas/' . $m['pessoa_id'])) ?>"><?= e($m['nome_local']) ?></a>
      <?php elseif (can('infleet.gerir') && $m['cpf_mascarado'] && (int) $m['ativo'] && (int) $m['disponivel']): ?>
        <form method="post" action="<?= e(url('/infleet/motoristas/cadastrar')) ?>" data-confirmar="Conferiu a identidade e a categoria deste motorista? O novo cadastro será criado inativo.">
          <?= csrf_field() ?><input type="hidden" name="infleet_id" value="<?= e($m['id']) ?>">
          <label class="campo"><span>Categoria</span><select name="categoria" required><option value="">Selecione</option><option value="colaborador">Colaborador</option><option value="terceiro">Terceiro</option><option value="visitante">Visitante</option></select></label>
          <label class="campo"><span>Empresa</span><input name="empresa" maxlength="150"></label>
          <button class="btn btn-secundario btn-sm" type="submit">Conferir e cadastrar</button>
        </form>
      <?php else: ?>Conferência pendente<?php endif; ?></td>
      <td><?= e(fmt_data_hora($m['sincronizado_em'])) ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$motoristas): ?><tr><td colspan="5">Nenhum motorista sincronizado.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
<div class="form-acoes">
  <?php if ($pagina > 1): ?><a class="btn btn-secundario" href="<?= e(url('/infleet', ['pagina' => $pagina - 1])) ?>">Página anterior</a><?php endif; ?>
  <span>Página <?= e($pagina) ?> · até 50 registros por tabela</span>
  <?php if ($mais): ?><a class="btn btn-secundario" href="<?= e(url('/infleet', ['pagina' => $pagina + 1])) ?>">Próxima página</a><?php endif; ?>
</div>
