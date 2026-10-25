<?php
/**
 * Aviso de privacidade (LGPD arts. 9º e 41). Texto base: revise com o Encarregado/Jurídico.
 * @var array $cfg  configurações (org_nome, org_cnpj, encarregado_*, retencao_*)
 */
$org = $cfg['org_nome'] ?? '' ?: 'Salinas Gold Mineração LTDA';
$encNome = $cfg['encarregado_nome'] ?? '';
$encEmail = $cfg['encarregado_email'] ?? '';
?>
<h1 class="titulo-central">Aviso de Privacidade</h1>
<p class="subtitulo-central">Como tratamos seus dados no controle de acesso às nossas unidades.</p>

<div class="texto-legal">
  <h2>Quem trata seus dados</h2>
  <p><strong><?= e($org) ?></strong><?= !empty($cfg['org_cnpj']) ? ' (CNPJ ' . e($cfg['org_cnpj']) . ')' : '' ?> é a controladora dos dados.
    Encarregado pelo tratamento de dados pessoais (DPO):
    <?php if ($encNome !== '' || $encEmail !== ''): ?>
      <strong><?= e($encNome ?: 'Encarregado') ?></strong><?php if ($encEmail !== ''): ?> — <a href="mailto:<?= e($encEmail) ?>"><?= e($encEmail) ?></a><?php endif; ?>.
    <?php else: ?>
      <em>contato a ser publicado pela empresa</em>.
    <?php endif; ?></p>

  <h2>Quais dados</h2>
  <ul>
    <li>Identificação: nome, CPF (guardado cifrado), RG ou matrícula, empresa e função.</li>
    <li>Contato: telefone, WhatsApp e e-mail.</li>
    <li>Acesso: data, hora, local e sentido de cada entrada e saída, destino, responsável interno, veículo e placa.</li>
    <li>Fotos tiradas na portaria (pessoa, veículo, placa ou documento). Não usamos reconhecimento facial.</li>
    <li>Tipo, número e validade de documentos de segurança (ASO, NRs, integração, CNH). O conteúdo do exame médico não é registrado.</li>
    <li>Para motoristas de veículos rastreados: percursos de GPS da frota.</li>
  </ul>

  <h2>Para que e com qual base legal</h2>
  <ul>
    <li><strong>Controle de acesso e segurança patrimonial</strong> — legítimo interesse (LGPD, art. 7º, IX).</li>
    <li><strong>Saúde e segurança no trabalho em mineração</strong> (NR-22, verificação SSMA, lista de evacuação) — cumprimento de obrigação legal (art. 7º, II) e proteção da vida e da integridade física (art. 7º, VII).</li>
    <li><strong>Registros de auditoria</strong> — cumprimento de obrigação legal e exercício regular de direitos (art. 7º, II e VI).</li>
  </ul>
  <p>Não pedimos consentimento porque o tratamento é necessário para entrar nas unidades. Os dados não são usados para outras finalidades nem vendidos.</p>

  <h2>Com quem compartilhamos</h2>
  <ul>
    <li>Cloudflare (verificação anti-robô do formulário público).</li>
    <li>Infleet e Google Maps (somente veículos e motoristas da frota rastreada) — podem armazenar dados fora do Brasil, com cláusulas contratuais de proteção.</li>
    <li>Autoridades, quando exigido por lei ou ordem judicial.</li>
  </ul>

  <h2>Por quanto tempo</h2>
  <ul>
    <li>Fotos da portaria: <?= (int) ($cfg['retencao_fotos_dias'] ?? 180) ?> dias.</li>
    <li>Solicitações de acesso: <?= (int) ($cfg['retencao_solicitacoes_meses'] ?? 12) ?> meses.</li>
    <li>Registros de entrada e saída: <?= (int) ($cfg['retencao_registros_meses'] ?? 24) ?> meses com observações; depois ficam só os dados mínimos.</li>
    <li>Cadastro de visitantes e terceiros sem acesso: <?= (int) ($cfg['retencao_cadastros_meses'] ?? 24) ?> meses; depois é anonimizado.</li>
    <li>Trilha de auditoria: <?= (int) ($cfg['retencao_auditoria_meses'] ?? 60) ?> meses.</li>
  </ul>

  <h2>Seus direitos</h2>
  <p>Você pode pedir confirmação, acesso, correção, anonimização, informação sobre compartilhamento e revisão do tratamento
    (LGPD, art. 18) ao Encarregado. Também pode reclamar à Autoridade Nacional de Proteção de Dados (ANPD).</p>

  <h2>Segurança</h2>
  <p>CPF cifrado, acesso por perfil, autenticação em dois fatores para administradores, trilha de auditoria imutável e backups cifrados.</p>
</div>
<p class="nota-central"><a href="<?= e(url('/solicitar-acesso')) ?>">Voltar à solicitação de acesso</a> · <a href="<?= e(url('/login')) ?>">Entrar</a></p>
