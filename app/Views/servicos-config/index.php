<?php
/**
 * @var array<int, array<string, mixed>> $areas
 * @var ?array<string, mixed> $areaAtual
 * @var array<int, array<string, mixed>> $usuarios
 * @var array<int, array<string, mixed>> $membros
 * @var array<int, array<string, mixed>> $categorias
 * @var array<int, array<string, mixed>> $campos
 * @var string $editarArea
 * @var string $editarCategoria
 * @var string $editarCampo
 */
use App\Controllers\ServicosConfigController;
use App\Services\FormularioDinamico;

$atalhosSla = ServicosConfigController::ATALHOS_SLA;
$urgencias = ServicosConfigController::URGENCIAS;
$papeisMembro = ServicosConfigController::PAPEIS_MEMBRO;
$tiposCampo = FormularioDinamico::TIPOS;
?>

<section class="cartao">
  <h2><?= e(t('Nova área de suporte')) ?></h2>
  <form method="post" action="<?= e(url('/servicos/config/areas')) ?>" class="form-pilha">
    <?= csrf_field() ?>
    <div class="grade-4">
      <label class="campo">
        <span><?= e(t('Nome da área')) ?> <span class="obrigatorio">*</span></span>
        <input type="text" name="nome" required maxlength="120" placeholder="<?= e(t('Ex.: Manutenção Elétrica')) ?>">
      </label>
      <label class="campo">
        <span><?= e(t('Identificador (slug)')) ?></span>
        <input type="text" name="slug" maxlength="60" placeholder="<?= e(t('Automático se vazio')) ?>">
      </label>
      <label class="campo">
        <span><?= e(t('Cor de identificação')) ?></span>
        <input type="color" name="cor" value="#024B37">
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
    <div class="form-acoes">
      <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar área')) ?></button>
    </div>
  </form>
</section>

<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad">
    <h2><?= e(t('Áreas de atendimento')) ?></h2>
  </div>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead>
        <tr>
          <th><?= e(t('Área')) ?></th>
          <th class="ocultar-sm"><?= e(t('Slug')) ?></th>
          <th class="ocultar-md"><?= e(t('Cor')) ?></th>
          <th class="ocultar-sm"><?= e(t('Equipe / Categorias / Campos')) ?></th>
          <th><?= e(t('Situação')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($areas === []): ?>
        <tr><td colspan="6" class="vazio mudo"><?= e(t('Nenhuma área de suporte cadastrada.')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($areas as $a): ?>
        <?php $selecionada = $areaAtual && $areaAtual['id'] === $a['id']; ?>
        <?php if ($editarArea === $a['id']): ?>
          <tr>
            <td colspan="6">
              <form method="post" action="<?= e(url('/servicos/config/areas/' . $a['id'])) ?>" class="form-pilha">
                <?= csrf_field() ?>
                <div class="grade-4">
                  <label class="campo">
                    <span><?= e(t('Nome da área')) ?> <span class="obrigatorio">*</span></span>
                    <input type="text" name="nome" required maxlength="120" value="<?= e($a['nome']) ?>">
                  </label>
                  <label class="campo">
                    <span><?= e(t('Identificador (slug)')) ?></span>
                    <input type="text" name="slug" required maxlength="60" value="<?= e($a['slug']) ?>">
                  </label>
                  <label class="campo">
                    <span><?= e(t('Cor de identificação')) ?></span>
                    <input type="color" name="cor" value="<?= e($a['cor'] ?: '#024B37') ?>">
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
                <div class="form-acoes">
                  <a class="btn btn-secundario btn-sm" href="<?= e(url('/servicos/config', ['area' => $a['id']])) ?>"><?= e(t('Cancelar')) ?></a>
                  <button class="btn btn-primario btn-sm" type="submit"><?= e(t('Salvar')) ?></button>
                </div>
              </form>
            </td>
          </tr>
        <?php else: ?>
          <tr>
            <td>
              <strong><?= e($a['nome']) ?></strong>
              <?php if (!empty($a['descricao'])): ?><span class="sub"><?= e($a['descricao']) ?></span><?php endif; ?>
            </td>
            <td class="ocultar-sm mono"><?= e($a['slug']) ?></td>
            <td class="ocultar-md"><code class="mono"><?= e($a['cor']) ?></code></td>
            <td class="ocultar-sm">
              <?= (int) ($a['total_membros'] ?? 0) ?> <?= e(t('membro(s)')) ?> ·
              <?= (int) ($a['total_categorias'] ?? 0) ?> <?= e(t('categoria(s)')) ?> ·
              <?= (int) ($a['total_campos'] ?? 0) ?> <?= e(t('campo(s)')) ?>
            </td>
            <td><?= (int) $a['ativo'] ? badge(t('Ativa'), 'sucesso') : badge(t('Inativa'), 'neutro') ?></td>
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
      <?= badge($areaAtual['slug'], 'ouro') ?>
    </div>
    <div class="cartao">
      <form method="post" action="<?= e(url('/servicos/config/areas/' . $areaId . '/membros')) ?>" class="filtros">
        <?= csrf_field() ?>
        <label class="campo">
          <span><?= e(t('Usuário ativo')) ?> <span class="obrigatorio">*</span></span>
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
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($membros === []): ?>
          <tr><td colspan="4" class="vazio mudo"><?= e(t('Nenhum membro vinculado a esta área.')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($membros as $m): ?>
          <tr>
            <td><strong><?= e($m['nome']) ?></strong></td>
            <td class="ocultar-sm mudo"><?= e($m['email']) ?></td>
            <td><?= $m['papel'] === 'gestor' ? badge(t('Gestor'), 'ouro') : badge(t('Atendente'), 'info') ?></td>
            <td class="acoes">
              <form method="post" action="<?= e(url('/servicos/config/areas/' . $areaId . '/membros/' . $m['usuario_id'] . '/remover')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="usuario_id" value="<?= e($m['usuario_id']) ?>">
                <button class="btn btn-fantasma btn-sm" type="submit"><?= icon('lixo', 'icon icon-sm') ?> <?= e(t('Remover')) ?></button>
              </form>
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
      <h3><?= e(t('Nova categoria')) ?></h3>
      <form method="post" action="<?= e(url('/servicos/config/categorias')) ?>" class="form-pilha">
        <?= csrf_field() ?>
        <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
        <div class="grade-3">
          <label class="campo">
            <span><?= e(t('Nome da categoria')) ?> <span class="obrigatorio">*</span></span>
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
                <option value="<?= (int) $nivel ?>"<?= selected($nivel, 3) ?>><?= e(t($rot)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="grade-4">
          <label class="campo">
            <span><?= e(t('SLA de resposta (atalho)')) ?></span>
            <select name="sla_resposta_atalho">
              <option value=""><?= e(t('— Sem SLA / personalizado —')) ?></option>
              <?php foreach ($atalhosSla as $min => $rot): ?>
                <option value="<?= (int) $min ?>"><?= e($rot) ?> (<?= (int) $min ?> min)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">
            <span><?= e(t('SLA de resposta (minutos)')) ?></span>
            <input type="number" name="sla_resposta_min" min="1" max="100800" placeholder="<?= e(t('Opcional')) ?>">
          </label>
          <label class="campo">
            <span><?= e(t('SLA de solução (atalho)')) ?></span>
            <select name="sla_solucao_atalho">
              <option value=""><?= e(t('— Sem SLA / personalizado —')) ?></option>
              <?php foreach ($atalhosSla as $min => $rot): ?>
                <option value="<?= (int) $min ?>"><?= e($rot) ?> (<?= (int) $min ?> min)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">
            <span><?= e(t('SLA de solução (minutos)')) ?></span>
            <input type="number" name="sla_solucao_min" min="1" max="100800" placeholder="<?= e(t('Opcional')) ?>">
          </label>
        </div>
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
        <div class="form-acoes">
          <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar categoria')) ?></button>
        </div>
      </form>
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
            <?php
              $respMin = isset($c['sla_resposta_min']) ? (int) $c['sla_resposta_min'] : 0;
              $solMin = isset($c['sla_solucao_min']) ? (int) $c['sla_solucao_min'] : 0;
              $respAtalho = isset($atalhosSla[$respMin]) ? $respMin : '';
              $respManual = ($respMin > 0 && !isset($atalhosSla[$respMin])) ? (string) $respMin : '';
              $solAtalho = isset($atalhosSla[$solMin]) ? $solMin : '';
              $solManual = ($solMin > 0 && !isset($atalhosSla[$solMin])) ? (string) $solMin : '';
            ?>
            <tr>
              <td colspan="6">
                <form method="post" action="<?= e(url('/servicos/config/categorias/' . $c['id'])) ?>" class="form-pilha">
                  <?= csrf_field() ?>
                  <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
                  <div class="grade-3">
                    <label class="campo">
                      <span><?= e(t('Nome da categoria')) ?> <span class="obrigatorio">*</span></span>
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
                          <option value="<?= (int) $nivel ?>"<?= selected($c['urgencia_padrao'] ?? 3, $nivel) ?>><?= e(t($rot)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <div class="grade-4">
                    <label class="campo">
                      <span><?= e(t('SLA de resposta (atalho)')) ?></span>
                      <select name="sla_resposta_atalho">
                        <option value=""><?= e(t('— Sem SLA / personalizado —')) ?></option>
                        <?php foreach ($atalhosSla as $min => $rot): ?>
                          <option value="<?= (int) $min ?>"<?= selected($respAtalho, $min) ?>><?= e($rot) ?> (<?= (int) $min ?> min)</option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="campo">
                      <span><?= e(t('SLA de resposta (minutos)')) ?></span>
                      <input type="number" name="sla_resposta_min" min="1" max="100800" value="<?= e($respManual) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('SLA de solução (atalho)')) ?></span>
                      <select name="sla_solucao_atalho">
                        <option value=""><?= e(t('— Sem SLA / personalizado —')) ?></option>
                        <?php foreach ($atalhosSla as $min => $rot): ?>
                          <option value="<?= (int) $min ?>"<?= selected($solAtalho, $min) ?>><?= e($rot) ?> (<?= (int) $min ?> min)</option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="campo">
                      <span><?= e(t('SLA de solução (minutos)')) ?></span>
                      <input type="number" name="sla_solucao_min" min="1" max="100800" value="<?= e($solManual) ?>">
                    </label>
                  </div>
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
            <tr>
              <td>
                <strong><?= e(str_repeat('↳ ', (int) ($c['nivel'] ?? 0)) . $c['nome']) ?></strong>
                <span class="sub"><?= e($c['caminho'] ?? $c['nome']) ?><?= !empty($c['descricao']) ? ' — ' . e($c['descricao']) : '' ?></span>
              </td>
              <td><?= e(ServicosConfigController::formatarSla(isset($c['sla_resposta_min']) ? (int) $c['sla_resposta_min'] : null)) ?></td>
              <td><?= e(ServicosConfigController::formatarSla(isset($c['sla_solucao_min']) ? (int) $c['sla_solucao_min'] : null)) ?></td>
              <td class="ocultar-sm"><?= e(label_t($urgencias, (int) ($c['urgencia_padrao'] ?? 3), '3 — Média')) ?></td>
              <td><?= (int) $c['ativo'] ? badge(t('Ativa'), 'sucesso') : badge(t('Inativa'), 'neutro') ?></td>
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
      <h3><?= e(t('Adicionar campo ao formulário')) ?></h3>
      <form method="post" action="<?= e(url('/servicos/config/campos')) ?>" class="form-pilha">
        <?= csrf_field() ?>
        <input type="hidden" name="area_id" value="<?= e($areaId) ?>">
        <div class="grade-3">
          <label class="campo">
            <span><?= e(t('Rótulo do campo')) ?> <span class="obrigatorio">*</span></span>
            <input type="text" name="rotulo" required maxlength="200" placeholder="<?= e(t('Ex.: Local exato da ocorrência')) ?>">
          </label>
          <label class="campo">
            <span><?= e(t('Tipo de resposta')) ?> <span class="obrigatorio">*</span></span>
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
                      <span><?= e(t('Rótulo do campo')) ?> <span class="obrigatorio">*</span></span>
                      <input type="text" name="rotulo" required maxlength="200" value="<?= e($f['rotulo']) ?>">
                    </label>
                    <label class="campo">
                      <span><?= e(t('Tipo de resposta')) ?> <span class="obrigatorio">*</span></span>
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
              <td>
                <strong><?= e($f['rotulo']) ?></strong>
                <?php if (!empty($f['ajuda'])): ?><span class="sub"><?= e($f['ajuda']) ?></span><?php endif; ?>
                <?php if (!empty($f['opcoes_lista'])): ?>
                  <span class="sub"><?= e(t('Opções')) ?>: <?= e(implode(' · ', $f['opcoes_lista'])) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e(label_t($tiposCampo, $f['tipo'], (string) $f['tipo'])) ?></td>
              <td class="ocultar-sm"><?= !empty($f['categoria_nome']) ? e($f['categoria_nome']) : '<span class="mudo">' . e(t('Todas as categorias')) . '</span>' ?></td>
              <td><?= (int) $f['obrigatorio'] ? badge(t('Sim'), 'aviso') : badge(t('Não'), 'neutro') ?></td>
              <td><?= (int) $f['ativo'] ? badge(t('Ativo'), 'sucesso') : badge(t('Inativo'), 'neutro') ?></td>
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
