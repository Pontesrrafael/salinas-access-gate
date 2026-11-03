<?php
/**
 * @var array<int, array<string, mixed>> $tokens
 * @var array<string, string>            $escopos
 * @var array<int, int>                  $validades
 * @var array{nome: string, token: string}|null $novo
 * @var string                           $baseApi
 */
$hoje = date('Y-m-d H:i:s');
?>
<?php if ($novo): ?>
  <section class="cartao cartao-sucesso">
    <h2><?= e(t('Token criado — exibido apenas uma vez')) ?></h2>
    <p><?= e(t('Token')) ?> <strong><?= e($novo['nome']) ?></strong>:
      <code class="mono destaque-senha"><?= e($novo['token']) ?></code>
      <button type="button" class="btn btn-secundario btn-sm" data-copiar="<?= e($novo['token']) ?>"><?= icon('copiar', 'icon icon-sm') ?> <?= e(t('Copiar')) ?></button></p>
    <p class="dica"><?= e(t('Guarde no cofre de senhas ou nas credenciais da ferramenta de B.I. O sistema guarda só uma impressão irreversível e não consegue mostrá-lo de novo.')) ?></p>
  </section>
<?php endif; ?>

<section class="cartao">
  <h2><?= e(t('Novo token')) ?></h2>
  <form method="post" action="<?= e(url('/configuracoes/api-tokens')) ?>">
    <?= csrf_field() ?>
    <div class="grade-2">
      <div class="campo">
        <label for="tk-nome"><?= e(t('Nome / uso')) ?> <span class="obrigatorio">*</span></label>
        <input id="tk-nome" name="nome" maxlength="120" required placeholder="<?= e(t('Ex.: Power BI — painel da portaria')) ?>">
      </div>
      <div class="campo">
        <label for="tk-validade"><?= e(t('Validade')) ?></label>
        <select id="tk-validade" name="validade">
          <?php foreach ($validades as $d): ?>
            <option value="<?= $d ?>"<?= $d === 90 ? ' selected' : '' ?>><?= e($d === 0 ? t('Sem validade (não recomendado)') : sprintf(t('%d dias'), $d)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <fieldset class="campo">
      <legend><?= e(t('Dados liberados')) ?> <span class="obrigatorio">*</span></legend>
      <?php foreach ($escopos as $chave => $rotulo): ?>
        <label class="checkbox"><input type="checkbox" name="escopos[]" value="<?= e($chave) ?>"> <?= e(t($rotulo)) ?></label>
      <?php endforeach; ?>
      <small class="dica"><?= e(t('A API nunca envia CPF, telefone, e-mail, fotos, anexos nem o texto dos chamados. Libere só o que o painel precisa.')) ?></small>
    </fieldset>
    <button type="submit" class="btn btn-ouro"><?= icon('mais') ?> <?= e(t('Gerar token')) ?></button>
  </form>
</section>

<section class="cartao sem-padding">
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr>
        <th><?= e(t('Nome')) ?></th><th><?= e(t('Início do token')) ?></th><th><?= e(t('Dados liberados')) ?></th>
        <th><?= e(t('Criado em')) ?></th><th><?= e(t('Expira em')) ?></th><th><?= e(t('Último uso')) ?></th><th><?= e(t('Situação')) ?></th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$tokens): ?>
        <tr><td colspan="8" class="vazio"><?= e(t('Nenhum token criado.')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($tokens as $tk):
        $escTk = is_array($tk['escopos'] ?? null) ? $tk['escopos'] : (json_decode((string) ($tk['escopos'] ?? '[]'), true) ?: []);
        $revogado = !empty($tk['revogado_em']);
        $expirado = !$revogado && !empty($tk['expira_em']) && (string) $tk['expira_em'] < $hoje;
      ?>
        <tr>
          <td><?= e((string) $tk['nome']) ?></td>
          <td><code class="mono"><?= e((string) $tk['prefixo']) ?>…</code></td>
          <td><?= e(implode(', ', array_map(fn($k) => t($escopos[$k] ?? $k), $escTk))) ?></td>
          <td><?= e(fmt_data_hora($tk['criado_em'] ?? null)) ?></td>
          <td><?= e($tk['expira_em'] ? fmt_data($tk['expira_em']) : t('Sem validade')) ?></td>
          <td><?= e($tk['ultimo_uso_em'] ? fmt_data_hora($tk['ultimo_uso_em']) : t('Nunca usado')) ?></td>
          <td><?= $revogado ? badge(t('Revogado'), 'neutro') : ($expirado ? badge(t('Expirado'), 'aviso') : badge(t('Ativo'), 'sucesso')) ?></td>
          <td>
            <?php if (!$revogado): ?>
              <form method="post" action="<?= e(url('/configuracoes/api-tokens/' . $tk['id'] . '/revogar')) ?>" data-confirmar="<?= e(t('Revogar este token? As integrações que o usam param na hora.')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secundario btn-sm"><?= e(t('Revogar')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="cartao">
  <h2><?= e(t('Como conectar')) ?></h2>
  <p><?= e(t('Endereço base')) ?>: <code class="mono"><?= e($baseApi) ?></code></p>
  <p><?= e(t('Envie o token no cabeçalho de cada requisição:')) ?> <code class="mono">Authorization: Bearer sag_…</code></p>
  <p class="dica"><?= e(t('No Power BI: Obter dados → Web → Avançado, informe a URL (ex.: /chamados) e acrescente o cabeçalho Authorization. O guia completo está em docs/11-API-BI.md.')) ?></p>
</section>
