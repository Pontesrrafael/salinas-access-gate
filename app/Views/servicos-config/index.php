<?php
/**
 * @var ?bool $isAdmin
 * @var array<int, array<string, mixed>> $areas
 * @var ?array<string, mixed> $areaAtual
 * @var ?string $corSugerida
 * @var ?array<int, string> $paletaCores
 * @var array<int, array<string, mixed>> $usuarios
 * @var array<int, array<string, mixed>> $membros
 * @var array<int, array<string, mixed>> $categorias
 * @var array<int, array<string, mixed>> $campos
 * @var string $editarArea
 * @var string $editarCategoria
 * @var string $editarCampo
 */
use App\Controllers\ServicosConfigController;
use App\Core\Auth;
use App\Services\Chamados;
use App\Services\FormularioDinamico;

$isAdmin = isset($isAdmin) ? (bool) $isAdmin : Auth::can('chamados.configurar');
if ($isAdmin) {
    slot('acoes', '<a class="btn btn-secundario" href="' . e(url('/configuracoes')) . '">' . icon('config') . e(t('Expediente e feriados')) . '</a>');
}

$atalhosSla = ServicosConfigController::ATALHOS_SLA;
$urgencias = Chamados::URGENCIAS;
$papeisMembro = $isAdmin ? ServicosConfigController::PAPEIS_MEMBRO : ['atendente' => 'Atendente'];
$tiposCampo = FormularioDinamico::TIPOS;
$paletaCores = $paletaCores ?? ServicosConfigController::PALETA_CORES;
$corSugerida = isset($corSugerida) && is_string($corSugerida) && $corSugerida !== ''
    ? ServicosConfigController::normalizarCor($corSugerida)
    : ServicosConfigController::sugerirCorArea($areas);
$camposSla = static function (array $dados, ?array $areaHerdada = null) use ($atalhosSla): void {
    $herdar = $areaHerdada !== null;
    $unidadesSla = ServicosConfigController::UNIDADES_SLA;
?>
  <div class="grade-2">
    <?php foreach (['resposta' => 'SLA padrão de resposta', 'solucao' => 'SLA padrão de solução'] as $tipo => $rotulo): ?>
      <?php
        $valor = $dados['sla_' . $tipo . '_min'] ?? null;
        $atalho = $valor !== null && isset($atalhosSla[(int) $valor]) ? (int) $valor : '';
        $decomposto = $valor !== null && $atalho === ''
            ? ServicosConfigController::decomporSla((int) $valor)
            : ['valor' => '', 'unidade' => 'min'];
      ?>
      <div class="form-pilha">
        <label class="campo">
          <span><?= e($herdar ? ($tipo === 'resposta' ? t('SLA de resposta (atalho)') : t('SLA de solução (atalho)')) : t($rotulo)) ?></span>
          <select name="sla_<?= e($tipo) ?>_atalho">
            <option value=""><?= e($herdar ? t('Herdar da área') : t('— Sem SLA / personalizado —')) ?></option>
            <?php foreach ($atalhosSla as $min => $rot): ?>
              <option value="<?= (int) $min ?>"<?= selected($atalho, $min) ?>><?= e($rot) ?> (<?= (int) $min ?> min)</option>
            <?php endforeach; ?>
          </select>
          <?php if ($herdar): ?>
            <small><?= e(t('Herdado da área: {valor}', ['valor' => ServicosConfigController::formatarSla(isset($areaHerdada['sla_' . $tipo . '_min']) ? (int) $areaHerdada['sla_' . $tipo . '_min'] : null)])) ?></small>
          <?php endif; ?>
        </label>
        <div class="grade-2 compacta">
          <label class="campo">
            <span><?= e($tipo === 'resposta' ? t('SLA de resposta (personalizado)') : t('SLA de solução (personalizado)')) ?></span>
            <input type="number" name="sla_<?= e($tipo) ?>_min" min="1" max="525600" step="1" value="<?= e($decomposto['valor']) ?>" placeholder="<?= e(t('Até 365 dias')) ?>">
          </label>
          <label class="campo">
            <span><?= e(t('Unidade')) ?></span>
            <select name="sla_<?= e($tipo) ?>_unidade">
              <?php foreach ($unidadesSla as $uCod => $uRot): ?>
                <option value="<?= e($uCod) ?>"<?= selected($decomposto['unidade'], $uCod) ?>><?= e(t($uRot)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    <label class="campo">
      <span><?= e(t('Regime de contagem do SLA')) ?></span>
      <select name="usar_horario_comercial">
        <option value=""<?= selected($dados['usar_horario_comercial'] ?? '', '') ?>><?= e($herdar ? t('Herdar da área') : t('Seguir padrão global')) ?></option>
        <option value="1"<?= selected($dados['usar_horario_comercial'] ?? '', '1') ?>><?= e(t('Horário comercial')) ?></option>
        <option value="0"<?= selected($dados['usar_horario_comercial'] ?? '', '0') ?>><?= e(t('Horas corridas (24x7)')) ?></option>
      </select>
      <?php if ($herdar): ?>
        <small><?= e(Chamados::textoSla([], $areaHerdada)) ?></small>
      <?php endif; ?>
    </label>
  </div>
  <p class="dica"><?= e(t('O valor personalizado (número + unidade, de 1 minuto até 365 dias) prevalece sobre o atalho. Com horário comercial ativo, "dias" contam dias úteis de expediente (descontando períodos fora do expediente, fins de semana e feriados). Deixe ambos vazios para herdar ou ficar sem SLA.')) ?></p>
<?php
};
?>
<div class="servicos-config">
<?php if ($isAdmin): ?>
<datalist id="paleta-cores-area">
  <?php foreach ($paletaCores as $corOpcao): ?>
    <option value="<?= e($corOpcao) ?>"><?= e($corOpcao) ?></option>
  <?php endforeach; ?>
</datalist>

<section class="cartao">
  <details class="secao-recolhivel" open>
    <summary><?= e(t('Nova área de suporte')) ?></summary>
    <form method="post" action="<?= e(url('/servicos/config/areas')) ?>" class="form-pilha">
      <?= csrf_field() ?>
      <div class="grade-4">
        <label class="campo">
          <span><?= e(t('Nome da área')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
          <input type="text" name="nome" required maxlength="120" placeholder="<?= e(t('Ex.: Manutenção Elétrica')) ?>">
        </label>
        <label class="campo">
          <span><?= e(t('Identificador (slug)')) ?></span>
          <input type="text" name="slug" maxlength="60" placeholder="<?= e(t('Automático se vazio')) ?>">
        </label>
        <label class="campo">
          <span><?= e(t('Cor de identificação')) ?></span>
          <input type="color" name="cor" value="<?= e($corSugerida) ?>" list="paleta-cores-area">
          <small><?= e(t('Sugerida automaticamente: {cor}', ['cor' => $corSugerida])) ?></small>
        </label>
        <label class="campo">
          <span><?= e(t('Ordem de exibição')) ?></span>
          <input type="number" name="ordem" min="0" max="9999" value="0">
        </label>
      </div>
      <div class="grade-2 compacta">
        <label class="campo campo-largo">
          <span><?= e(t('Descrição da área')) ?></span>
          <input type="text" name="descricao" maxlength="500" placeholder="<?= e(t('Resumo dos serviços atendidos por esta área')) ?>">
        </label>
      </div>
      <?php $camposSla([]); ?>
      <div class="form-acoes">
        <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar área')) ?></button>
      </div>
    </form>
  </details>
</section>
<?php endif; ?>

<section class="cartao sem-padding" data-tour="filtros">
  <div class="cartao-cabecalho pad">
    <h2><?= e(t('Áreas de atendimento')) ?></h2>
  </div>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead>
        <tr>
          <th><?= e(t('Área')) ?></th>
          <?php if ($isAdmin): ?>
            <th class="ocultar-sm"><?= e(t('Slug')) ?></th>
            <th class="ocultar-md"><?= e(t('Cor')) ?></th>
          <?php endif; ?>
          <th class="ocultar-sm"><?= e(t('Equipe / Categorias / Campos')) ?></th>
          <th><?= e(t('Situação')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($areas === []): ?>
        <tr><td colspan="<?= $isAdmin ? 6 : 4 ?>" class="vazio mudo"><?= e(t('Nenhuma área de suporte cadastrada.')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($areas as $a): ?>
        <?php $selecionada = $areaAtual && $areaAtual['id'] === $a['id']; ?>
        <?php $corArea = ServicosConfigController::normalizarCor($a['cor'] ?? null); ?>
        <?php if ($editarArea === $a['id']): ?>
          <tr>
            <td colspan="<?= $isAdmin ? 6 : 4 ?>">
              <form method="post" action="<?= e(url('/servicos/config/areas/' . $a['id'])) ?>" class="form-pilha">
                <?= csrf_field() ?>
                <?php if ($isAdmin): ?>
                  <div class="grade-4">
                    <label class="campo">
                      <span><?= e(t('Nome da área')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
                      <input type="text" name="nome" required maxlength="120" value="<?= e($a['nome']) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Identificador (slug)')) ?></span>
                      <input type="text" name="slug" required maxlength="60" value="<?= e($a['slug']) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Cor de identificação')) ?></span>
                      <input type="color" name="cor" value="<?= e($corArea) ?>" list="paleta-cores-area">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Ordem de exibição')) ?></span>
                      <input type="number" name="ordem" min="0" max="9999" value="<?= (int) ($a['ordem'] ?? 0) ?>">
                    </label>
                  </div>
                  <div class="grade-2 compacta">
                    <label class="campo">
                      <span><?= e(t('Descrição da área')) ?></span>
                      <input type="text" name="descricao" maxlength="500" value="<?= e($a['descricao'] ?? '') ?>">
                    </label>
                    <label class="checkbox">
                      <input type="checkbox" name="ativo" value="1"<?= checked($a['ativo']) ?>>
                      <span><?= e(t('Área ativa para novos chamados')) ?></span>
                    </label>
                  </div>
                <?php else: ?>
                  <div class="grade-2 compacta">
                    <label class="campo campo-largo">
                      <span><?= e(t('Descrição da área')) ?></span>
                      <input type="text" name="descricao" maxlength="500" value="<?= e($a['descricao'] ?? '') ?>">
                    </label>
                  </div>
                <?php endif; ?>
                <?php $camposSla($a); ?>
                <div class="form-acoes">
                  <a class="btn btn-secundario btn-sm" href="<?= e(url('/servicos/config', ['area' => $a['id']])) ?>"><?= e(t('Cancelar')) ?></a>
                  <button class="btn btn-primario btn-sm" type="submit"><?= e(t('Salvar')) ?></button>
                </div>
              </form>
            </td>
          </tr>
        <?php else: ?>
          <tr>
            <td data-rotulo="<?= e(t('Área')) ?>">
              <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="5" fill="<?= e($corArea) ?>"/></svg>
              <strong><?= e($a['nome']) ?></strong>
              <?php if (!empty($a['descricao'])): ?><span class="sub"><?= e($a['descricao']) ?></span><?php endif; ?>
            </td>
            <?php if ($isAdmin): ?>
              <td class="ocultar-sm mono" data-rotulo="<?= e(t('Slug')) ?>"><?= e($a['slug']) ?></td>
              <td class="ocultar-md" data-rotulo="<?= e(t('Cor')) ?>">
                <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="5" fill="<?= e($corArea) ?>"/></svg>
                <code class="mono"><?= e($corArea) ?></code>
              </td>
            <?php endif; ?>
            <td class="ocultar-sm" data-rotulo="<?= e(t('Equipe / Categorias / Campos')) ?>">
              <?= (int) ($a['total_membros'] ?? 0) ?> <?= e(t('membro(s)')) ?> ·
              <?= (int) ($a['total_categorias'] ?? 0) ?> <?= e(t('categoria(s)')) ?> ·
              <?= (int) ($a['total_campos'] ?? 0) ?> <?= e(t('campo(s)')) ?>
            </td>
            <td data-rotulo="<?= e(t('Situação')) ?>"><?= (int) $a['ativo'] ? badge(t('Ativa'), 'sucesso') : badge(t('Inativa'), 'neutro') ?></td>
            <td class="acoes">
              <a class="btn <?= $selecionada ? 'btn-primario' : 'btn-secundario' ?> btn-sm" href="<?= e(url('/servicos/config', ['area' => $a['id']])) ?>">
                <?= icon('config', 'icon icon-sm') ?> <?= e($selecionada ? t('Selecionada') : t('Configurar')) ?>
              </a>
              <a class="btn btn-fantasma btn-sm" href="<?= e(url('/servicos/config', ['area' => $a['id'], 'editar_area' => $a['id']])) ?>">
                <?= icon('editar', 'icon icon-sm') ?> <?= e(t('Editar')) ?>
              </a>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($areaAtual !== null): ?>
  <?php $areaId = (string) $areaAtual['id']; ?>

  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad">
      <h2><?= icon('usuarios') ?> <?= e(t('Equipe da área')) ?>: <?= e($areaAtual['nome']) ?></h2>
      <?php if ($isAdmin): ?><?= badge($areaAtual['slug'], 'ouro') ?><?php endif; ?>
    </div>
    <div class="cartao">
      <form method="post" action="<?= e(url('/servicos/config/areas/' . $areaId . '/membros')) ?>" class="filtros">
        <?= csrf_field() ?>
        <label class="campo">
          <span><?= e(t('Usuário ativo')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
          <select name="usuario_id" required>
            <option value=""><?= e(t('— Selecione —')) ?></option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?= e($u['id']) ?>"><?= e($u['nome']) ?> (<?= e($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="campo">
          <span><?= e(t('Papel na área')) ?></span>
          <select name="papel">
            <?php foreach ($papeisMembro as $k => $rot): ?>
              <option value="<?= e($k) ?>"><?= e(t($rot)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Vincular membro')) ?></button>
      </form>
    </div>
    <div class="tabela-rolagem">
      <table class="tabela">
        <thead>
          <tr>
            <th><?= e(t('Nome')) ?></th>
            <th class="ocultar-sm"><?= e(t('E-mail')) ?></th>
            <th><?= e(t('Papel na área')) ?></th>
            <th><?= e(t('Chamados abertos')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($membros === []): ?>
          <tr><td colspan="5" class="vazio mudo"><?= e(t('Nenhum membro vinculado a esta área.')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($membros as $m): ?>
          <?php
            $abertosQtd = (int) ($m['chamados_abertos'] ?? 0);
            $abertosLista = is_array($m['chamados_lista'] ?? null) ? $m['chamados_lista'] : [];
            $abertosNumeros = implode(', ', array_map(static fn (array $ch): string => '#' . $ch['numero'], $abertosLista));
          ?>
          <tr>
            <td data-rotulo="<?= e(t('Nome')) ?>"><strong><?= e($m['nome']) ?></strong></td>
            <td class="ocultar-sm mudo" data-rotulo="<?= e(t('E-mail')) ?>"><?= e($m['email']) ?></td>
            <td data-rotulo="<?= e(t('Papel na área')) ?>"><?= $m['papel'] === 'gestor' ? badge(t('Gestor'), 'ouro') : badge(t('Atendente'), 'info') ?></td>
            <td data-rotulo="<?= e(t('Chamados abertos')) ?>">
              <?php if ($abertosQtd > 0): ?>
                <?= badge(t('{n} em aberto', ['n' => $abertosQtd]), 'aviso') ?>
                <span class="sub"><?= e($abertosNumeros) ?> · <?= e(t('Ao remover, voltam para a triagem')) ?></span>
              <?php else: ?>
                <span class="mudo">0</span>
              <?php endif; ?>
            </td>
            <td class="acoes">
              <?php if ($isAdmin || ($m['papel'] ?? '') !== 'gestor'): ?>
                <form method="post" action="<?= e(url('/servicos/config/areas/' . $areaId . '/membros/' . $m['usuario_id'] . '/remover')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="usuario_id" value="<?= e($m['usuario_id']) ?>">
                  <button class="btn btn-fantasma btn-sm" type="submit"><?= icon('lixo', 'icon icon-sm') ?> <?= e(t('Remover')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad">
      <h2><?= e(t('Categorias e SLAs')) ?> — <?= e($areaAtual['nome']) ?></h2>
    </div>
    <div class="cartao">
      <details class="secao-recolhivel" open>
        <summary><?= e(t('Nova categoria')) ?></summary>
        <form method="post" action="<?= e(url('/servicos/config/categorias')) ?>" class="form-pilha">
          <?= csrf_field() ?>
          <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
          <div class="grade-3">
            <label class="campo">
              <span><?= e(t('Nome da categoria')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
              <input type="text" name="nome" required maxlength="150" placeholder="<?= e(t('Ex.: Manutenção Predial')) ?>">
            </label>
            <label class="campo">
              <span><?= e(t('Categoria pai (hierarquia)')) ?></span>
              <select name="parent_id">
                <option value=""><?= e(t('— Raiz (sem categoria pai) —')) ?></option>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= e($cat['id']) ?>"><?= e(str_repeat('— ', (int) ($cat['nivel'] ?? 0)) . $cat['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="campo">
              <span><?= e(t('Urgência padrão')) ?></span>
              <select name="urgencia_padrao">
                <?php foreach ($urgencias as $nivel => $rot): ?>
                  <option value="<?= (int) $nivel ?>"<?= selected($nivel, 3) ?>><?= (int) $nivel ?> — <?= e(t($rot)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <?php $camposSla([], $areaAtual); ?>
          <div class="grade-3">
            <label class="campo campo-largo">
              <span><?= e(t('Descrição / orientação para o solicitante')) ?></span>
              <input type="text" name="descricao" maxlength="500">
            </label>
            <label class="campo">
              <span><?= e(t('Ordem')) ?></span>
              <input type="number" name="ordem" min="0" max="9999" value="0">
            </label>
          </div>
          <?php if ($isAdmin): ?>
            <p class="dica"><?= e(t('O expediente, os dias úteis, os feriados, o e-mail e a retenção de chamados são definidos em')) ?> <a href="<?= e(url('/configuracoes')) ?>"><?= e(t('Configurações')) ?> → <?= e(t('Chamados')) ?></a>.</p>
          <?php else: ?>
            <p class="dica"><?= e(t('O expediente, os dias úteis e os feriados seguem o calendário global definido pela administração (TI).')) ?></p>
          <?php endif; ?>
          <div class="form-acoes">
            <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar categoria')) ?></button>
          </div>
        </form>
      </details>
    </div>

    <div class="tabela-rolagem">
      <table class="tabela">
        <thead>
          <tr>
            <th><?= e(t('Categoria (árvore)')) ?></th>
            <th><?= e(t('SLA Resposta')) ?></th>
            <th><?= e(t('SLA Solução')) ?></th>
            <th class="ocultar-sm"><?= e(t('Urgência padrão')) ?></th>
            <th><?= e(t('Situação')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($categorias === []): ?>
          <tr><td colspan="6" class="vazio mudo"><?= e(t('Nenhuma categoria cadastrada nesta área.')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($categorias as $c): ?>
          <?php if ($editarCategoria === $c['id']): ?>
            <tr>
              <td colspan="6">
                <form method="post" action="<?= e(url('/servicos/config/categorias/' . $c['id'])) ?>" class="form-pilha">
                  <?= csrf_field() ?>
                  <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
                  <div class="grade-3">
                    <label class="campo">
                      <span><?= e(t('Nome da categoria')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
                      <input type="text" name="nome" required maxlength="150" value="<?= e($c['nome']) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Categoria pai (hierarquia)')) ?></span>
                      <select name="parent_id">
                        <option value=""><?= e(t('— Raiz (sem categoria pai) —')) ?></option>
                        <?php foreach ($categorias as $opCat): ?>
                          <?php if ($opCat['id'] === $c['id']) { continue; } ?>
                          <option value="<?= e($opCat['id']) ?>"<?= selected($c['parent_id'] ?? '', $opCat['id']) ?>>
                            <?= e(str_repeat('— ', (int) ($opCat['nivel'] ?? 0)) . $opCat['nome']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="campo">
                      <span><?= e(t('Urgência padrão')) ?></span>
                      <select name="urgencia_padrao">
                        <?php foreach ($urgencias as $nivel => $rot): ?>
                          <option value="<?= (int) $nivel ?>"<?= selected($c['urgencia_padrao'] ?? 3, $nivel) ?>><?= (int) $nivel ?> — <?= e(t($rot)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <?php $camposSla($c, $areaAtual); ?>
                  <div class="grade-3">
                    <label class="campo">
                      <span><?= e(t('Descrição')) ?></span>
                      <input type="text" name="descricao" maxlength="500" value="<?= e($c['descricao'] ?? '') ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Ordem')) ?></span>
                      <input type="number" name="ordem" min="0" max="9999" value="<?= (int) ($c['ordem'] ?? 0) ?>">
                    </label>
                    <label class="checkbox">
                      <input type="checkbox" name="ativo" value="1"<?= checked($c['ativo']) ?>>
                      <span><?= e(t('Categoria ativa')) ?></span>
                    </label>
                  </div>
                  <div class="form-acoes">
                    <a class="btn btn-secundario btn-sm" href="<?= e(url('/servicos/config', ['area' => $areaId])) ?>"><?= e(t('Cancelar')) ?></a>
                    <button class="btn btn-primario btn-sm" type="submit"><?= e(t('Salvar')) ?></button>
                  </div>
                </form>
              </td>
            </tr>
          <?php else: ?>
            <?php $urgPadrao = (int) ($c['urgencia_padrao'] ?? 3); $sla = Chamados::slaEfetivo($c, $areaAtual); ?>
            <tr>
              <td data-rotulo="<?= e(t('Categoria (árvore)')) ?>">
                <strong><?= e(str_repeat('↳ ', (int) ($c['nivel'] ?? 0)) . $c['nome']) ?></strong>
                <span class="sub"><?= e($c['caminho'] ?? $c['nome']) ?><?= !empty($c['descricao']) ? ' — ' . e($c['descricao']) : '' ?></span>
                <span class="sub"><?= e(Chamados::textoSla($c, $areaAtual)) ?></span>
              </td>
              <td data-rotulo="<?= e(t('SLA Resposta')) ?>"><?= e(ServicosConfigController::formatarSla($sla['resposta_min'])) ?><span class="sub"><?= e($sla['resposta_min'] === null ? t('Sem SLA definido') : (isset($c['sla_resposta_min']) ? t('SLA da categoria') : t('SLA da área'))) ?></span></td>
              <td data-rotulo="<?= e(t('SLA Solução')) ?>"><?= e(ServicosConfigController::formatarSla($sla['solucao_min'])) ?><span class="sub"><?= e($sla['solucao_min'] === null ? t('Sem SLA definido') : (isset($c['sla_solucao_min']) ? t('SLA da categoria') : t('SLA da área'))) ?></span></td>
              <td class="ocultar-sm" data-rotulo="<?= e(t('Urgência padrão')) ?>"><?= $urgPadrao ?> — <?= e(label_t($urgencias, $urgPadrao, 'Média')) ?></td>
              <td data-rotulo="<?= e(t('Situação')) ?>"><?= (int) $c['ativo'] ? badge(t('Ativa'), 'sucesso') : badge(t('Inativa'), 'neutro') ?></td>
              <td class="acoes">
                <a class="btn btn-fantasma btn-sm" href="<?= e(url('/servicos/config', ['area' => $areaId, 'editar_categoria' => $c['id']])) ?>">
                  <?= icon('editar', 'icon icon-sm') ?> <?= e(t('Editar')) ?>
                </a>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad">
      <h2><?= e(t('Construtor de formulário')) ?> — <?= e($areaAtual['nome']) ?></h2>
    </div>
    <div class="cartao">
      <details class="secao-recolhivel" open>
        <summary><?= e(t('Adicionar campo ao formulário')) ?></summary>
        <form method="post" action="<?= e(url('/servicos/config/campos')) ?>" class="form-pilha">
          <?= csrf_field() ?>
          <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
          <div class="grade-3">
            <label class="campo">
              <span><?= e(t('Rótulo do campo')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
              <input type="text" name="rotulo" required maxlength="200" placeholder="<?= e(t('Ex.: Local exato da ocorrência')) ?>">
            </label>
            <label class="campo">
              <span><?= e(t('Tipo de resposta')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
              <select name="tipo">
                <?php foreach ($tiposCampo as $tk => $trot): ?>
                  <option value="<?= e($tk) ?>"><?= e(t($trot)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="campo">
              <span><?= e(t('Aplicar na categoria')) ?></span>
              <select name="categoria_id">
                <option value=""><?= e(t('— Todas as categorias da área —')) ?></option>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= e($cat['id']) ?>"><?= e($cat['caminho'] ?? $cat['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="grade-2 compacta">
            <label class="campo">
              <span><?= e(t('Opções (uma por linha, para Lista ou Múltipla escolha)')) ?></span>
              <textarea name="opcoes" rows="3" placeholder="<?= e("Opção 1\nOpção 2\nOpção 3") ?>"></textarea>
            </label>
            <div class="form-pilha">
              <label class="campo">
                <span><?= e(t('Texto de ajuda (opcional)')) ?></span>
                <input type="text" name="ajuda" maxlength="500" placeholder="<?= e(t('Instrução exibida abaixo do campo')) ?>">
              </label>
              <label class="checkbox">
                <input type="checkbox" name="obrigatorio" value="1">
                <span><?= e(t('Preenchimento obrigatório na abertura do chamado')) ?></span>
              </label>
            </div>
          </div>
          <div class="form-acoes">
            <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar campo')) ?></button>
          </div>
        </form>
      </details>
    </div>

    <div class="tabela-rolagem">
      <table class="tabela">
        <thead>
          <tr>
            <th><?= e(t('Campo')) ?></th>
            <th><?= e(t('Tipo')) ?></th>
            <th class="ocultar-sm"><?= e(t('Categoria')) ?></th>
            <th><?= e(t('Obrigatório')) ?></th>
            <th><?= e(t('Situação')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($campos === []): ?>
          <tr><td colspan="6" class="vazio mudo"><?= e(t('Nenhum campo adicional configurado para esta área.')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($campos as $f): ?>
          <?php if ($editarCampo === $f['id']): ?>
            <tr>
              <td colspan="6">
                <form method="post" action="<?= e(url('/servicos/config/campos/' . $f['id'])) ?>" class="form-pilha">
                  <?= csrf_field() ?>
                  <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
                  <div class="grade-3">
                    <label class="campo">
                      <span><?= e(t('Rótulo do campo')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
                      <input type="text" name="rotulo" required maxlength="200" value="<?= e($f['rotulo']) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Tipo de resposta')) ?> <span class="obrigatorio" aria-hidden="true">*</span></span>
                      <select name="tipo">
                        <?php foreach ($tiposCampo as $tk => $trot): ?>
                          <option value="<?= e($tk) ?>"<?= selected($f['tipo'], $tk) ?>><?= e(t($trot)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="campo">
                      <span><?= e(t('Aplicar na categoria')) ?></span>
                      <select name="categoria_id">
                        <option value=""><?= e(t('— Todas as categorias da área —')) ?></option>
                        <?php foreach ($categorias as $cat): ?>
                          <option value="<?= e($cat['id']) ?>"<?= selected($f['categoria_id'] ?? '', $cat['id']) ?>>
                            <?= e($cat['caminho'] ?? $cat['nome']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <div class="grade-2 compacta">
                    <label class="campo">
                      <span><?= e(t('Opções (uma por linha, para Lista ou Múltipla escolha)')) ?></span>
                      <textarea name="opcoes" rows="3"><?= e(implode("\n", $f['opcoes_lista'] ?? [])) ?></textarea>
                    </label>
                    <div class="form-pilha">
                      <label class="campo">
                        <span><?= e(t('Texto de ajuda (opcional)')) ?></span>
                        <input type="text" name="ajuda" maxlength="500" value="<?= e($f['ajuda'] ?? '') ?>">
                      </label>
                      <label class="checkbox">
                        <input type="checkbox" name="obrigatorio" value="1"<?= checked($f['obrigatorio']) ?>>
                        <span><?= e(t('Preenchimento obrigatório na abertura do chamado')) ?></span>
                      </label>
                      <label class="checkbox">
                        <input type="checkbox" name="ativo" value="1"<?= checked($f['ativo']) ?>>
                        <span><?= e(t('Campo ativo')) ?></span>
                      </label>
                    </div>
                  </div>
                  <div class="form-acoes">
                    <a class="btn btn-secundario btn-sm" href="<?= e(url('/servicos/config', ['area' => $areaId])) ?>"><?= e(t('Cancelar')) ?></a>
                    <button class="btn btn-primario btn-sm" type="submit"><?= e(t('Salvar')) ?></button>
                  </div>
                </form>
              </td>
            </tr>
          <?php else: ?>
            <tr>
              <td data-rotulo="<?= e(t('Campo')) ?>">
                <strong><?= e($f['rotulo']) ?></strong>
                <?php if (!empty($f['ajuda'])): ?><span class="sub"><?= e($f['ajuda']) ?></span><?php endif; ?>
                <?php if (!empty($f['opcoes_lista'])): ?>
                  <span class="sub"><?= e(t('Opções')) ?>: <?= e(implode(' · ', $f['opcoes_lista'])) ?></span>
                <?php endif; ?>
              </td>
              <td data-rotulo="<?= e(t('Tipo')) ?>"><?= e(label_t($tiposCampo, $f['tipo'], (string) $f['tipo'])) ?></td>
              <td class="ocultar-sm" data-rotulo="<?= e(t('Categoria')) ?>"><?= !empty($f['categoria_nome']) ? e($f['categoria_nome']) : '<span class="mudo">' . e(t('Todas as categorias')) . '</span>' ?></td>
              <td data-rotulo="<?= e(t('Obrigatório')) ?>"><?= (int) $f['obrigatorio'] ? badge(t('Sim'), 'aviso') : badge(t('Não'), 'neutro') ?></td>
              <td data-rotulo="<?= e(t('Situação')) ?>"><?= (int) $f['ativo'] ? badge(t('Ativo'), 'sucesso') : badge(t('Inativo'), 'neutro') ?></td>
              <td class="acoes">
                <form method="post" action="<?= e(url('/servicos/config/campos/' . $f['id'] . '/mover')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="direcao" value="cima">
                  <button class="btn btn-fantasma btn-sm" type="submit" title="<?= e(t('Mover para cima')) ?>" aria-label="<?= e(t('Mover para cima')) ?>">↑</button>
                </form>
                <form method="post" action="<?= e(url('/servicos/config/campos/' . $f['id'] . '/mover')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="direcao" value="baixo">
                  <button class="btn btn-fantasma btn-sm" type="submit" title="<?= e(t('Mover para baixo')) ?>" aria-label="<?= e(t('Mover para baixo')) ?>">↓</button>
                </form>
                <form method="post" action="<?= e(url('/servicos/config/campos/' . $f['id'] . '/alternar')) ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-fantasma btn-sm" type="submit">
                    <?= (int) $f['ativo'] ? e(t('Desativar')) : e(t('Ativar')) ?>
                  </button>
                </form>
                <a class="btn btn-fantasma btn-sm" href="<?= e(url('/servicos/config', ['area' => $areaId, 'editar_campo' => $f['id']])) ?>">
                  <?= icon('editar', 'icon icon-sm') ?> <?= e(t('Editar')) ?>
                </a>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($campos !== []): ?>
    <section class="cartao">
      <div class="cartao-cabecalho">
        <h2><?= e(t('Pré-visualização do formulário dinâmico')) ?> — <?= e($areaAtual['nome']) ?></h2>
      </div>
      <?= FormularioDinamico::renderizar($campos) ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
</div>
