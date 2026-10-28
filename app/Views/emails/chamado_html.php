<?php
declare(strict_types=1);

/**
 * Modelo HTML de notificação de chamado (padrão visual Salinas Access Gate, sem imagens externas).
 * Por conformidade LGPD, não inclui notas internas, descrição livre, beneficiário nem respostas de formulário.
 *
 * @var string $assunto
 * @var string $tituloEvento
 * @var string $resumoEvento
 * @var string $destinatarioNome
 * @var string $numero
 * @var string $tituloChamado
 * @var string $areaNome
 * @var string $categoriaNome
 * @var string $statusRotulo
 * @var string $prioridadeRotulo
 * @var string $corArea
 * @var string $linkChamado
 */
$corTopo = (isset($corArea) && preg_match('/^#[0-9A-Fa-f]{6}$/', $corArea) === 1) ? $corArea : '#024B37';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($assunto) ?></title>
</head>
<body style="margin:0;padding:24px 12px;background-color:#F4F6F5;font-family:Calibri,'Segoe UI',Arial,sans-serif;color:#1F2933;line-height:1.5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background-color:#FFFFFF;border:1px solid #D8E0DC;border-radius:8px;overflow:hidden;">
    <tr>
      <td style="background-color:<?= e($corTopo) ?>;padding:18px 24px;border-bottom:4px solid #FFCD00;">
        <div style="font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#C6D53E;font-weight:bold;">
          Salinas Gold Mineração · Salinas Access Gate
        </div>
        <div style="font-size:20px;font-weight:bold;color:#FFFFFF;margin-top:4px;">
          <?= e($tituloEvento) ?>
        </div>
      </td>
    </tr>
    <tr>
      <td style="padding:24px;">
        <?php if (!empty($destinatarioNome)): ?>
          <p style="margin:0 0 12px 0;font-size:15px;">
            <?= e(t('Olá, {nome}.', ['nome' => $destinatarioNome])) ?>
          </p>
        <?php endif; ?>

        <p style="margin:0 0 18px 0;font-size:15px;color:#334155;">
          <?= e($resumoEvento) ?>
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8FAF9;border:1px solid #E2E8F0;border-left:4px solid #024B37;border-radius:6px;padding:14px 16px;margin-bottom:20px;">
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;width:130px;"><?= e(t('Chamado')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;font-weight:bold;color:#024B37;"><?= e($numero) ?></td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;"><?= e(t('Título')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;font-weight:bold;color:#1F2933;"><?= e($tituloChamado) ?></td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;"><?= e(t('Área')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;"><?= e($areaNome) ?></td>
          </tr>
          <?php if (!empty($categoriaNome) && $categoriaNome !== '—'): ?>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;"><?= e(t('Categoria do serviço')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;"><?= e($categoriaNome) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;"><?= e(t('Status')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;"><?= e($statusRotulo) ?></td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;"><?= e(t('Prioridade')) ?>:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;"><?= e($prioridadeRotulo) ?></td>
          </tr>
        </table>

        <p style="margin:0 0 20px 0;">
          <a href="<?= e($linkChamado) ?>" style="display:inline-block;background-color:#024B37;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:14px;padding:10px 20px;border-radius:6px;border-bottom:2px solid #FFCD00;">
            <?= e(t('Acessar chamado no sistema')) ?>
          </a>
        </p>

        <p style="margin:0;font-size:12px;color:#707070;word-break:break-all;">
          <?= e(t('Link direto')) ?>: <a href="<?= e($linkChamado) ?>" style="color:#024B37;"><?= e($linkChamado) ?></a>
        </p>
      </td>
    </tr>
    <tr>
      <td style="background-color:#F8FAF9;padding:14px 24px;border-top:1px solid #E2E8F0;font-size:12px;color:#707070;">
        <?= e(t('Mensagem automática do Salinas Access Gate. Por privacidade (LGPD), detalhes completos, anexos e histórico ficam restritos ao acesso autenticado no sistema.')) ?>
      </td>
    </tr>
  </table>
</body>
</html>
