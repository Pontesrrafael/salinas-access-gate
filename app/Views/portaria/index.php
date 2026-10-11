<?php if (!$locais): ?>
  <div class="alerta alerta-aviso">Nenhum local ativo cadastrado. <?php if (can('locais.gerir')): ?><a href="<?= e(url('/locais')) ?>">Cadastre um local</a>.<?php endif; ?></div>
<?php return; endif; ?>

<div id="faixa-conexao" class="faixa-conexao" role="status" aria-live="polite" hidden>
  <?= icon('nuvem-off', 'icon') ?> <span id="faixa-conexao-texto">Sem conexão com o servidor.</span>
  <button type="button" class="btn btn-sm btn-secundario" id="btn-sincronizar" hidden>Sincronizar agora</button>
</div>

<div class="grade-portaria">
  <div class="coluna-principal">

    <!-- 1. Local e movimentação -->
    <section class="cartao">
      <div class="grade-2 compacta">
        <form method="post" action="<?= e(url('/portaria/local')) ?>" id="form-local">
          <?= csrf_field() ?>
          <label class="campo">
            <span class="rotulo-caps">Portaria / Local</span>
            <select name="local_id" id="sel-local" class="alto" data-autosubmit>
              <?php foreach ($locais as $l): ?>
                <option value="<?= e($l['id']) ?>"<?= selected($l['id'], $local['id'] ?? '') ?>><?= e($l['nome']) ?> (<?= e(TIPOS_LOCAL[$l['tipo']] ?? $l['tipo']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <noscript><button class="btn btn-secundario btn-sm">Trocar local</button></noscript>
        </form>
        <div class="campo">
          <span class="rotulo-caps">Movimentação</span>
          <div class="alternador" role="radiogroup" aria-label="Movimentação">
            <label class="alt-opcao alt-entrada"><input type="radio" name="sentido" value="entrada" form="form-portaria" checked><?= icon('entrada') ?> ENTRADA</label>
            <label class="alt-opcao alt-saida"><input type="radio" name="sentido" value="saida" form="form-portaria"><?= icon('saida') ?> SAÍDA</label>
          </div>
        </div>
      </div>
    </section>

    <form method="post" action="<?= e(url('/portaria/registrar')) ?>" enctype="multipart/form-data" id="form-portaria"
          data-local="<?= e($local['id'] ?? '') ?>"
          data-max-fotos="<?= (int) $uploadCfg['max_files'] ?>" data-max-mb="<?= (int) $uploadCfg['max_mb'] ?>"
          data-exigir-destino="<?= $exigirDestino ? '1' : '0' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="_idem" value="<?= e($idem) ?>">
      <input type="hidden" name="local_id" value="<?= e($local['id'] ?? '') ?>">
      <input type="hidden" name="pessoa_id" id="pessoa_id">
      <input type="hidden" name="veiculo_id" id="veiculo_id">
      <input type="hidden" name="registro_id" id="registro_id">

      <!-- 2. Pessoa -->
      <section class="cartao">
        <div class="cartao-cabecalho">
          <h2><?= icon('pessoas', 'icon icon-sm') ?> Buscar pessoa <span class="obrigatorio">*</span></h2>
          <div class="botoes">
            <button type="button" class="btn btn-ouro btn-sm" id="btn-ler-qr" title="Ler QR do convite (F3)"><?= icon('qr', 'icon icon-sm') ?> Ler QR</button>
            <?php if (can('pessoas.criar')): ?><button type="button" class="btn btn-secundario btn-sm" data-abrir="dlg-pessoa"><?= icon('mais', 'icon icon-sm') ?> Cadastro rápido</button><?php endif; ?>
          </div>
        </div>
        <div class="busca" id="busca-pessoa-box">
          <?= icon('busca', 'icon busca-icone') ?>
          <input type="search" id="busca-pessoa" class="alto" placeholder="Nome, CPF, matrícula, empresa ou leia o QR (F2)" autocomplete="off" autofocus>
          <ul class="resultados" id="res-pessoa" hidden></ul>
        </div>
        <p class="dica" id="dica-pessoa">Digite pelo menos 2 caracteres para buscar.</p>
        <div id="pessoa-card" class="selecionado" hidden></div>
        <div id="solicitacao-box" hidden></div>
      </section>

      <!-- 3. Veículo -->
      <section class="cartao">
        <div class="cartao-cabecalho">
          <h2><?= icon('veiculo', 'icon icon-sm') ?> Buscar veículo <small class="mudo">(opcional)</small></h2>
          <?php if (can('veiculos.criar')): ?><button type="button" class="btn btn-secundario btn-sm" data-abrir="dlg-veiculo"><?= icon('mais', 'icon icon-sm') ?> Novo veículo</button><?php endif; ?>
        </div>
        <div class="busca" id="busca-veiculo-box">
          <?= icon('busca', 'icon busca-icone') ?>
          <input type="search" id="busca-veiculo" class="alto maiusculo" placeholder="Placa, modelo, TAG ou empresa" autocomplete="off">
          <ul class="resultados" id="res-veiculo" hidden></ul>
        </div>
        <p class="dica" id="dica-veiculo">Pessoa a pé? Deixe em branco.</p>
        <div id="veiculo-card" class="selecionado" hidden></div>
      </section>

      <!-- 4. Detalhes -->
      <section class="cartao">
        <div class="cartao-cabecalho">
          <h2 class="rotulo-caps">Detalhes</h2>
          <span id="padrao-status" class="dica"></span>
        </div>
        <div id="padrao-pendente" class="alerta alerta-aviso compacto" hidden>
          Esta pessoa possui dados padrão cadastrados e você já digitou outros valores.
          <button type="button" class="btn btn-secundario btn-sm" id="btn-usar-padrao">Usar dados do cadastro</button>
        </div>
        <div class="grade-2 compacta">
          <label class="campo">
            <span>Destino / Setor<?= $exigirDestino ? ' <span class="obrigatorio">*</span>' : '' ?></span>
            <input type="text" name="destino" id="destino" maxlength="150" placeholder="Ex.: Oficina, Subestação">
          </label>
          <div class="campo campo-largo">
            <span>Responsável interno</span>
            <input type="hidden" name="responsavel_interno_id" id="responsavel_interno_id" value="<?= e($respPadrao['id'] ?? '') ?>">
            <div class="busca">
              <input type="text" name="responsavel_interno" id="responsavel" maxlength="150" autocomplete="off"
                     value="<?= e($respPadrao['nome'] ?? '') ?>"
                     placeholder="Buscar por nome, matrícula, empresa, e-mail ou telefone">
              <ul class="resultados" id="res-responsavel" hidden></ul>
            </div>
            <div id="responsavel-info" class="dica"<?= $respPadrao ? '' : ' hidden' ?>>
              <?php if ($respPadrao): ?><?= e(trim(($respPadrao['empresa'] ?? '') . ' · ' . ($respPadrao['telefone_principal'] ?? 'Sem telefone') . ' · ' . ($respPadrao['email_principal'] ?? ''), ' ·')) ?><?php endif; ?>
            </div>
          </div>
          <label class="campo campo-largo">
            <span>Observações</span>
            <textarea name="observacoes" id="observacoes" rows="2" maxlength="2000" placeholder="Notas operacionais"></textarea>
          </label>
        </div>
      </section>

      <!-- 5. Fotos -->
      <section class="cartao">
        <div class="cartao-cabecalho">
          <h2 class="rotulo-caps">Fotos / anexos <small class="mudo normal" id="fotos-contador">(0/<?= (int) $uploadCfg['max_files'] ?> · JPG/PNG/WEBP · até <?= (int) $uploadCfg['max_mb'] ?> MB)</small></h2>
          <div class="botoes">
            <label class="btn btn-secundario btn-sm"><?= icon('camera', 'icon icon-sm') ?> Câmera
              <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" id="foto-camera" class="oculto-acessivel"></label>
            <label class="btn btn-secundario btn-sm"><?= icon('imagem', 'icon icon-sm') ?> Galeria
              <input type="file" accept="image/jpeg,image/png,image/webp" multiple id="foto-galeria" class="oculto-acessivel"></label>
          </div>
        </div>
        <input type="file" name="fotos[]" id="fotos-envio" multiple hidden>
        <p class="dica" id="fotos-vazio">Nenhuma foto anexada. Use a câmera ou selecione da galeria.</p>
        <div class="fotos-grade" id="fotos-grade"></div>
      </section>

      <div id="bloqueios" class="alerta alerta-erro" hidden></div>
      <div id="alertas-criticos" class="alerta-critico" role="alert" hidden>
        <div class="alerta-critico-topo"><?= icon('alerta', 'icon icon-lg') ?> <strong>ATENÇÃO — confirme antes de liberar</strong></div>
        <ul id="alertas-criticos-lista"></ul>
        <label class="checkbox alerta-critico-ciente"><input type="checkbox" name="ciente_alertas" value="1" id="ciente-alertas">
          <span>Conferi e autorizo a entrada mesmo assim <small>(fica registrado no histórico e na auditoria)</small></span></label>
      </div>
      <div id="avisos" class="alerta alerta-aviso" hidden></div>

      <p class="atalhos-dica nao-imprimir"><?= icon('teclado', 'icon icon-sm') ?>
        <kbd>F2</kbd> pessoa · <kbd>F3</kbd> QR · <kbd>F4</kbd> veículo · <kbd>F8</kbd>/<kbd>F9</kbd> entrada/saída ·
        <kbd>Ctrl</kbd>+<kbd>Enter</kbd> confirmar · <kbd>Esc</kbd> limpar · <button type="button" class="link-botao" data-abrir="dlg-atalhos">todos (<kbd>?</kbd>)</button></p>

      <button type="submit" id="btn-registrar" class="btn btn-registrar entrada" disabled>
        <?= icon('entrada', 'icon ic-entrada') ?><?= icon('saida', 'icon ic-saida') ?> <span id="btn-registrar-texto">CONFIRMAR ENTRADA</span>
      </button>
    </form>
  </div>

  <aside class="coluna-lateral">
    <?php if ($confirmacao): ?>
      <section class="cartao cartao-sucesso">
        <div class="confirmacao"><?= icon('ok', 'icon icon-lg') ?>
          <div>
            <strong><?= e($confirmacao['titulo']) ?></strong>
            <p><?= e($confirmacao['descricao']) ?></p>
            <?php if ($confirmacao['fotos']): ?><p class="dica"><?= (int) $confirmacao['fotos'] ?> foto(s) anexada(s).</p><?php endif; ?>
            <?php foreach ($confirmacao['avisos'] as $a): ?><p class="dica aviso-texto"><?= icon('alerta', 'icon icon-sm') ?> <?= e($a) ?></p><?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <section class="cartao presentes-portaria" id="presentes-portaria">
      <details id="det-presentes" open>
      <summary class="cartao-cabecalho"><h2>Dentro agora <span class="badge badge-info" id="presentes-total"><?= count($presentes) ?></span></h2>
        <a class="dica" href="<?= e(url('/presenca', ['local_id' => $local['id'] ?? ''])) ?>">ver tudo</a></summary>
      <?php if (!$presentes): ?>
        <p class="dica">Ninguém registrado como presente neste local.</p>
      <?php else: ?>
      <form method="post" action="<?= e(url('/portaria/saida-rapida')) ?>" id="form-saida-rapida" data-confirmar-lote>
        <?= csrf_field() ?>
        <input type="hidden" name="voltar" value="/portaria">
        <input type="search" class="filtro-presentes" id="filtro-presentes" placeholder="Filtrar por nome, empresa ou placa" autocomplete="off" aria-label="Filtrar presentes">
        <ul class="lista-presentes" id="lista-presentes">
          <?php foreach ($presentes as $p): $chave = $p['pessoa_id'] . '|' . $p['local_id']; ?>
          <li data-busca="<?= e(mb_strtolower($p['pessoa_nome'] . ' ' . ($p['pessoa_empresa'] ?? '') . ' ' . ($p['veiculo_placa'] ?? ''))) ?>">
            <label class="presente-sel"><input type="checkbox" name="itens[]" value="<?= e($chave) ?>" aria-label="Selecionar <?= e($p['pessoa_nome']) ?>"></label>
            <span class="presente-info"><strong><?= e($p['pessoa_nome']) ?></strong>
              <small><?= e(implode(' · ', array_filter([CATEGORIAS[$p['pessoa_categoria']] ?? '', $p['pessoa_empresa'], $p['veiculo_placa'], 'desde ' . substr(fmt_data_hora($p['entrada_em']), -5)]))) ?></small></span>
            <button type="submit" class="btn btn-saida btn-sm" name="itens[]" value="<?= e($chave) ?>" data-saida-unica title="Registrar a saída de <?= e($p['pessoa_nome']) ?>"><?= icon('saida', 'icon icon-sm') ?> Saída</button>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="presentes-acoes">
          <label class="checkbox"><input type="checkbox" id="presentes-todos"> Todos visíveis</label>
          <button type="submit" class="btn btn-saida" id="btn-saida-lote" disabled data-lote><?= icon('saida', 'icon icon-sm') ?> Saída dos selecionados (<span id="lote-qtd">0</span>)</button>
        </div>
      </form>
      <?php endif; ?>
      </details>
    </section>
    <section class="cartao instrucoes">
      <h2 class="titulo-primario">Pronto para operar</h2>
      <ol class="passos">
        <li>Confira o local e o tipo de movimentação.</li>
        <li>Busque a pessoa (obrigatório) e, se houver, o veículo.</li>
        <li>Visitantes e terceiros precisam de solicitação <strong>aprovada para hoje</strong>.</li>
        <li>Anexe fotos pela câmera ou galeria e confirme.</li>
      </ol>
      <p class="dica">Bloqueios são verificados também no servidor no momento do registro.</p>
      <p class="dica"><a href="<?= e(url('/presenca')) ?>">Ver quem está no site</a></p>
    </section>
  </aside>
</div>

<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2>Movimentações recentes — <?= e($local['nome'] ?? '') ?></h2></div>
  <?php if (!$recentes): ?>
    <p class="mudo vazio">Nenhuma movimentação registrada ainda.</p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Data/Hora</th><th>Mov.</th><th>Pessoa</th><th class="ocultar-md">Empresa</th><th class="ocultar-sm">Placa</th><th class="ocultar-lg">Destino</th><th class="ocultar-lg">Porteiro</th><th class="ocultar-sm">Fotos</th></tr></thead>
      <tbody>
      <?php foreach ($recentes as $r): ?>
        <tr>
          <td class="nowrap"><a href="<?= e(url('/registros/' . $r['id'])) ?>"><?= e(fmt_data_hora($r['data_hora'])) ?></a></td>
          <td><?= $r['sentido'] === 'entrada' ? badge('Entrada', 'sucesso') : badge('Saída', 'neutro') ?></td>
          <td><?= e($r['pessoa_nome'] ?? '—') ?></td>
          <td class="ocultar-md mudo"><?= e($r['pessoa_empresa'] ?? $r['veiculo_empresa'] ?? '—') ?></td>
          <td class="ocultar-sm mono"><?= e($r['placa'] ?? '—') ?></td>
          <td class="ocultar-lg mudo"><?= e($r['destino'] ?? '—') ?></td>
          <td class="ocultar-lg mudo"><?= e($r['porteiro_nome'] ?? '—') ?></td>
          <td class="ocultar-sm"><?= (int) $r['fotos'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Diálogo: cadastro rápido de pessoa -->
<dialog id="dlg-pessoa" class="dialogo">
  <form id="form-rapido-pessoa" data-endpoint="<?= e(url('/api/pessoas/rapido')) ?>">
    <h2>Cadastro rápido — Pessoa</h2>
    <input type="hidden" name="solicitacao_id" value="">
    <p class="dica" id="cpf-da-solicitacao" hidden>O CPF informado na solicitação será usado (não é exibido).</p>
    <label class="campo"><span>Nome completo <span class="obrigatorio">*</span></span><input name="nome" required maxlength="200"></label>
    <div class="grade-2 compacta">
      <label class="campo"><span>CPF</span><input name="cpf" inputmode="numeric" maxlength="14" data-mascara="cpf"></label>
      <label class="campo"><span>Tipo</span>
        <select name="categoria"><?php foreach (CATEGORIAS as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($k, 'visitante') ?>><?= e($v) ?></option><?php endforeach; ?></select>
      </label>
      <label class="campo"><span>Empresa</span><input name="empresa" maxlength="150"></label>
      <label class="campo"><span>Telefone</span><input name="telefone" maxlength="30" data-mascara="telefone"></label>
    </div>
    <p class="erro-form" hidden></p>
    <div class="dialogo-acoes">
      <button type="button" class="btn btn-secundario" data-fechar>Cancelar</button>
      <button type="submit" class="btn btn-primario">Cadastrar</button>
    </div>
  </form>
</dialog>

<!-- Diálogo: cadastro rápido de veículo -->
<dialog id="dlg-veiculo" class="dialogo">
  <form id="form-rapido-veiculo" data-endpoint="<?= e(url('/api/veiculos/rapido')) ?>">
    <h2>Cadastro rápido — Veículo</h2>
    <div class="grade-2 compacta">
      <label class="campo"><span>Placa <span class="obrigatorio">*</span></span><input name="placa" required maxlength="10" class="maiusculo mono"></label>
      <label class="campo"><span>Tipo</span>
        <select name="tipo"><?php foreach (TIPOS_VEICULO as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
      </label>
    </div>
    <label class="campo"><span>Marca / Modelo</span><input name="marca_modelo" maxlength="120"></label>
    <div class="grade-2 compacta">
      <label class="campo"><span>Cor</span><input name="cor" maxlength="40"></label>
      <label class="campo"><span>Empresa</span><input name="empresa" maxlength="150"></label>
    </div>
    <p class="erro-form" hidden></p>
    <div class="dialogo-acoes">
      <button type="button" class="btn btn-secundario" data-fechar>Cancelar</button>
      <button type="submit" class="btn btn-primario">Cadastrar</button>
    </div>
  </form>
</dialog>

<!-- Diálogo: atalhos de teclado -->
<dialog id="dlg-atalhos" class="dialogo">
  <h2>Atalhos de teclado da portaria</h2>
  <table class="tabela tabela-atalhos">
    <tbody>
      <tr><td><kbd>F2</kbd></td><td>Buscar pessoa (troca a pessoa selecionada)</td></tr>
      <tr><td><kbd>F4</kbd></td><td>Buscar veículo</td></tr>
      <tr><td><kbd>F3</kbd></td><td>Ler QR do convite pela câmera ou foto</td></tr>
      <tr><td><kbd>F8</kbd> / <kbd>F9</kbd></td><td>Entrada / Saída (fora de campos de texto também <kbd>E</kbd> / <kbd>S</kbd>)</td></tr>
      <tr><td><kbd>Ctrl</kbd> + <kbd>Enter</kbd></td><td>Confirmar o registro (com alerta vermelho, leva até a caixa de ciência — marque com <kbd>Espaço</kbd>)</td></tr>
      <tr><td><kbd>Esc</kbd></td><td>Fecha a lista de resultados; de novo, limpa tudo para a próxima pessoa</td></tr>
      <tr><td><kbd>?</kbd></td><td>Esta ajuda</td></tr>
    </tbody>
  </table>
  <p class="dica">Leitor de QR USB: com esta tela aberta, basta ler o convite — não precisa clicar em nenhum campo.</p>
  <div class="dialogo-acoes"><button type="button" class="btn btn-primario" data-fechar>Entendi</button></div>
</dialog>

<!-- Diálogo: leitura de QR pela câmera -->
<dialog id="dlg-qr" class="dialogo">
  <h2>Ler QR do convite</h2>
  <div class="qr-video" id="qr-video-box" hidden><video id="qr-video" playsinline muted></video><div class="qr-mira" aria-hidden="true"></div></div>
  <p class="dica" id="qr-status">Aponte a câmera para o QR do convite.</p>
  <label class="btn btn-secundario" id="qr-foto-label"><?= icon('camera', 'icon icon-sm') ?> Tirar foto do QR
    <input type="file" accept="image/*" capture="environment" id="qr-foto" class="oculto-acessivel"></label>
  <label class="campo"><span>Ou digite/cole o código</span><input type="text" id="qr-manual" class="mono" autocomplete="off" placeholder="SAG1.…"></label>
  <div class="dialogo-acoes">
    <button type="button" class="btn btn-secundario" data-fechar>Cancelar</button>
    <button type="button" class="btn btn-primario" id="qr-manual-ok">Usar código</button>
  </div>
</dialog>

<template id="tpl-tipos-foto">
  <?php foreach (TIPOS_FOTO as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
</template>
