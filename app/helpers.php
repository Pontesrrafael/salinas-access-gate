<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Http;

/** Escapa saída HTML (prevenção de XSS). */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    return Http::url($path, $query);
}

function asset(string $path): string
{
    $file = App::root('public/assets/' . ltrim($path, '/'));
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return Http::url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

/** Define conteúdo HTML (já escapado) para uma área do layout, ex.: slot('acoes', '<a ...>'). */
function slot(string $nome, string $html): void
{
    \App\Core\View::$slots[$nome] = $html;
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function can(string $permissao): bool
{
    return Auth::can($permissao);
}

function usuario(): ?array
{
    return Auth::user();
}

/** Valor antigo de formulário (após erro de validação). */
function old(array $old, string $key, mixed $default = ''): string
{
    return (string) ($old[$key] ?? $default ?? '');
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return in_array($v, [1, '1', true, 'on'], true) ? ' checked' : '';
}

function fmt_data(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_data_hora(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function hoje(): string
{
    return date('Y-m-d');
}

function papel_label(?string $p): string
{
    return Auth::PAPEIS[$p ?? ''] ?? '—';
}

const CATEGORIAS = ['colaborador' => 'Colaborador', 'visitante' => 'Visitante', 'terceiro' => 'Terceiro'];
const TIPOS_LOCAL = ['mina' => 'Mina', 'escritorio' => 'Escritório'];
const TIPOS_DOC = [
    'aso' => 'ASO', 'nr22' => 'NR-22', 'nr35' => 'NR-35', 'nr33' => 'NR-33',
    'integracao' => 'Integração', 'cnh' => 'CNH', 'outro' => 'Outro',
];
const TIPOS_VEICULO = [
    'carro' => 'Carro', 'caminhonete' => 'Caminhonete', 'caminhao' => 'Caminhão',
    'moto' => 'Moto', 'onibus' => 'Ônibus / Van', 'maquina' => 'Máquina',
];
const TIPOS_FOTO = [
    'pessoa' => 'Foto da pessoa', 'veiculo' => 'Foto do veículo', 'placa' => 'Foto da placa',
    'documento' => 'Documento/autorização', 'outra' => 'Outra evidência',
];
const STATUS_SOLICITACAO = [
    'aguardando_ssma' => 'Aguardando SSMA', 'pendente' => 'Pendente', 'aprovada' => 'Aprovada',
    'recusada' => 'Recusada', 'realizada' => 'Realizada', 'expirada' => 'Expirada',
];

/** valido | proximo_vencimento | vencido (30 dias de antecedência) */
function status_documento(string $validade): string
{
    $diff = (int) floor((strtotime($validade) - strtotime(date('Y-m-d'))) / 86400);
    if ($diff < 0) {
        return 'vencido';
    }
    return $diff <= 30 ? 'proximo_vencimento' : 'valido';
}

function badge(string $texto, string $tipo = 'neutro'): string
{
    return '<span class="badge badge-' . e($tipo) . '">' . e($texto) . '</span>';
}

function badge_status_solicitacao(string $status): string
{
    $map = [
        'pendente' => 'aviso', 'aprovada' => 'sucesso', 'recusada' => 'perigo',
        'realizada' => 'neutro', 'expirada' => 'neutro', 'aguardando_ssma' => 'info',
    ];
    return badge(STATUS_SOLICITACAO[$status] ?? $status, $map[$status] ?? 'neutro');
}

/** Ícones SVG inline (sem dependência externa). */
function icon(string $nome, string $classe = 'icon'): string
{
    $paths = [
        'painel'    => '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
        'portaria'  => '<path d="M13 4h3a2 2 0 0 1 2 2v14"/><path d="M2 20h3"/><path d="M13 20h9"/><path d="M10 12v.01"/><path d="M13 4.56v16.157a.6.6 0 0 1-.78.57l-6.44-2.147A1 1 0 0 1 5 18.18V5.79a1 1 0 0 1 .78-.976l6.44-1.432a.6.6 0 0 1 .78.57z"/>',
        'solicit'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'escudo'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'pessoas'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'veiculo'   => '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
        'local'     => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'relatorio' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M8 17h2"/><path d="M14 17h2"/>',
        'auditoria' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'usuarios'  => '<circle cx="18" cy="15" r="3"/><circle cx="9" cy="7" r="4"/><path d="M10 15H6a4 4 0 0 0-4 4v2"/><path d="m21.7 16.4-.9-.3"/><path d="m15.2 13.9-.9-.3"/><path d="m16.6 18.7.3-.9"/><path d="m19.1 12.2.3-.9"/>',
        'config'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'sair'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'entrada'   => '<path d="M12 17V3"/><path d="m6 11 6 6 6-6"/><path d="M19 21H5"/>',
        'saida'     => '<path d="m18 9-6-6-6 6"/><path d="M12 3v14"/><path d="M5 21h14"/>',
        'busca'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'mais'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'alerta'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'ok'        => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
        'x'         => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'camera'    => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'imagem'    => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'copiar'    => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'olho'      => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'lixo'      => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'editar'    => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
        'imprimir'  => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
        'menu'      => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'relogio'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    ];
    $p = $paths[$nome] ?? '';
    return '<svg class="' . e($classe) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
