<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Retencao;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

/** Captura o executor transacional para impedir regressão do plano preparado mas não executado. */
final class RetencaoTestePdo extends PDO
{
    public array $consultas = [];
    public bool $falharSatisfacao = false;
    public bool $transacao = false;
    public bool $commitado = false;
    public bool $revertido = false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->transacao = true; return true; }
    public function inTransaction(): bool { return $this->transacao; }
    public function commit(): bool { $this->commitado = true; $this->transacao = false; return true; }
    public function rollBack(): bool { $this->revertido = true; $this->transacao = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new RetencaoTesteStmt($this, $query); }
}
final class RetencaoTesteStmt extends PDOStatement
{
    public function __construct(private RetencaoTestePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->db->consultas[] = [$this->sql, $params];
        if ($this->db->falharSatisfacao && str_starts_with($this->sql, 'UPDATE chamado_satisfacao')) { throw new RuntimeException('Falha de limpeza simulada'); }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return str_starts_with($this->sql, 'SELECT id FROM chamados') ? [['id' => 'retido']] : [];
    }
}

$agora = new DateTimeImmutable('2026-10-28 12:00:00');

// 1. Prazo mínimo de retenção de chamados é 6 meses
expect(Retencao::normalizarPrazoChamados(1) === 6, 'Prazo de retenção de chamados abaixo de 6 meses deve ser elevado para 6.');
expect(Retencao::normalizarPrazoChamados(0) === 6, 'Prazo zero deve ser elevado para o mínimo de 6 meses.');
expect(Retencao::normalizarPrazoChamados(24) === 24, 'Prazo válido (>= 6 meses) deve ser preservado.');

// 2. Elegibilidade de chamados (apenas status = 'fechado', sem anonimizado_em e com fechado_em anterior ao corte)
$chamadoAntigoFechado = [
    'id'                => 'c-antigo',
    'numero'            => 101,
    'area_id'           => 'area-ssma',
    'categoria_id'      => 'cat-epi',
    'status'            => 'fechado',
    'prioridade'        => 2,
    'urgencia'          => 2,
    'impacto'           => 3,
    'descricao'         => 'Solicitação com detalhes pessoais do colaborador.',
    'beneficiario_nome' => 'José da Silva',
    'created_at'        => '2024-01-10 08:00:00',
    'fechado_em'        => '2024-01-15 17:30:00',
    'anonimizado_em'    => null,
    'satisfacao_nota'   => 5,
    'respostas'         => [
        ['campo_id' => 'f1', 'rotulo' => 'Matrícula', 'valor' => '12345'],
        ['campo_id' => 'f2', 'rotulo' => 'Setor',     'valor' => 'Planta de Beneficiamento'],
    ],
    'acompanhamentos'   => [
        ['id' => 'ac1', 'tipo' => 'comentario', 'texto' => 'Comentário com telefone (65) 99999-0000'],
        ['id' => 'ac2', 'tipo' => 'solucao',    'texto' => 'EPI entregue mediante assinatura.'],
    ],
    'anexos'            => [
        ['id' => 'an1', 'storage_path' => '2024/01/11111111-1111-4111-8111-111111111111.pdf'],
    ],
];

expect(Retencao::elegivelChamado($chamadoAntigoFechado, $agora, 24), 'Chamado fechado há mais de 24 meses deve ser elegível à retenção.');
expect(!Retencao::elegivelChamado($chamadoAntigoFechado, $agora, 36), 'Chamado fechado há 33 meses não deve ser elegível quando o prazo é 36 meses.');

$chamadoRecente = $chamadoAntigoFechado;
$chamadoRecente['fechado_em'] = '2026-08-01 10:00:00';
expect(!Retencao::elegivelChamado($chamadoRecente, $agora, 6), 'Chamado fechado há menos de 6 meses não pode ser anonimizado.');

$chamadoSolucionado = $chamadoAntigoFechado;
$chamadoSolucionado['status'] = 'solucionado';
expect(!Retencao::elegivelChamado($chamadoSolucionado, $agora, 6), 'Chamado ainda não fechado (ex.: solucionado) não pode ser anonimizado.');

$chamadoJaAnonimizado = $chamadoAntigoFechado;
$chamadoJaAnonimizado['anonimizado_em'] = '2026-05-01 00:00:00';
expect(Retencao::elegivelChamado($chamadoJaAnonimizado, $agora, 6), 'Marcação antiga precisa de saneamento.');
$chamadoJaAnonimizado['retencao_versao'] = 1;
expect(!Retencao::elegivelChamado($chamadoJaAnonimizado, $agora, 6), 'Saneamento completo é idempotente.');

// 3. Transformação de anonimização: limpa dados pessoais e preserva metadados estatísticos e satisfação
$anon = Retencao::anonimizarRegistroChamado($chamadoAntigoFechado, $agora);
expect($anon['descricao'] === null, 'descricao deve virar NULL após retenção.');
expect($anon['beneficiario_nome'] === null, 'beneficiario_nome deve virar NULL após retenção.');
expect($anon['anonimizado_em'] === '2026-10-28 12:00:00', 'anonimizado_em deve registrar a data/hora da retenção.');
expect($anon['anexos'] === [], 'Lista de anexos deve ser esvaziada na retenção.');
expect($anon['respostas'][0]['valor'] === '[removido por retenção]' && $anon['respostas'][1]['valor'] === '[removido por retenção]', 'Valores de chamado_respostas devem virar [removido por retenção].');
expect($anon['acompanhamentos'][0]['texto'] === '[removido por retenção]' && $anon['acompanhamentos'][1]['texto'] === '[removido por retenção]', 'Textos de chamado_acompanhamentos devem virar [removido por retenção].');
expect(
    $anon['numero'] === 101
    && $anon['area_id'] === 'area-ssma'
    && $anon['categoria_id'] === 'cat-epi'
    && $anon['created_at'] === '2024-01-10 08:00:00'
    && $anon['fechado_em'] === '2024-01-15 17:30:00'
    && $anon['status'] === 'fechado'
    && $anon['prioridade'] === 2
    && $anon['satisfacao_nota'] === 5,
    'Número, área, categoria, datas, status, prioridade e nota de satisfação devem ser mantidos.'
);

// 4. Montagem das consultas SQL de retenção de chamados (sem banco)
$consultaContagem = Retencao::consultaCandidatosChamados(3, true);
expect(str_contains($consultaContagem['sql'], 'SELECT COUNT(*) FROM chamados'), 'Simulação deve consultar COUNT(*) em chamados.');
expect(str_contains($consultaContagem['sql'], "status = 'fechado'") && str_contains($consultaContagem['sql'], 'retencao_versao = 0'), 'Consulta deve filtrar fechados ainda não anonimizados.');
expect($consultaContagem['params'] === [6], 'Consulta deve aplicar o mínimo de 6 meses nos parâmetros.');

$idsLote = ['id-1', 'id-2'];
$plano = Retencao::planoSqlChamados($idsLote);
expect($plano['anexos_delete']['sql'] === 'DELETE FROM chamado_anexos WHERE chamado_id IN (?, ?)' && $plano['anexos_delete']['params'] === $idsLote, 'Plano SQL deve apagar linhas de chamado_anexos.');
expect($plano['respostas_update']['sql'] === 'UPDATE chamado_respostas SET valor = ? WHERE chamado_id IN (?, ?)' && $plano['respostas_update']['params'] === ['[removido por retenção]', 'id-1', 'id-2'], 'Plano SQL deve substituir chamado_respostas.valor por [removido por retenção].');
expect($plano['acompanhamentos_update']['sql'] === 'UPDATE chamado_acompanhamentos SET texto = ?, usuario_id = NULL WHERE chamado_id IN (?, ?)' && $plano['acompanhamentos_update']['params'] === ['[removido por retenção]', 'id-1', 'id-2'], 'Plano SQL deve substituir chamado_acompanhamentos.texto por [removido por retenção].');
expect(str_contains($plano['chamados_update']['sql'], 'descricao = NULL, beneficiario_nome = NULL, anonimizado_em = COALESCE(anonimizado_em, NOW()), retencao_versao = 1') && $plano['chamados_update']['params'] === $idsLote, 'Plano SQL deve limpar descricao/beneficiario_nome e marcar anonimizado_em.');

expect($anon['titulo'] === 'Chamado anonimizado' && $anon['solicitante_id'] === null && $anon['atendente_id'] === null, 'Título livre e vínculos pessoais removidos.');
$comSatisfacao = $chamadoAntigoFechado + ['satisfacao' => ['nota' => 4, 'comentario' => 'Telefone pessoal', 'respondido_por' => 'pessoa']];
$limpo = Retencao::anonimizarRegistroChamado($comSatisfacao, $agora);
expect($limpo['satisfacao'] === ['nota' => 4, 'comentario' => null, 'respondido_por' => null], 'Retenção preserva nota e remove comentário e autor da satisfação.');
expect($plano['satisfacao_update']['params'] === $idsLote, 'Limpeza de satisfação usa os mesmos chamados do lote.');

// 5. Regras e consultas de expurgo da email_fila (enviados há mais de 30 dias)
expect(
    Retencao::elegivelEmailFila(['enviado_em' => '2026-09-15 10:00:00'], $agora, 30),
    'E-mail enviado há mais de 30 dias deve ser elegível para exclusão.'
);
expect(
    !Retencao::elegivelEmailFila(['enviado_em' => '2026-10-15 10:00:00'], $agora, 30),
    'E-mail enviado há menos de 30 dias deve ser mantido.'
);
expect(
    !Retencao::elegivelEmailFila(['enviado_em' => null], $agora, 30),
    'E-mail ainda não enviado (pendente/falho) não deve ser apagado pela limpeza de enviados.'
);

$sqlEmailSimular = Retencao::consultaEmailFila(30, true);
$sqlEmailApagar = Retencao::consultaEmailFila(30, false);
expect(str_contains($sqlEmailSimular['sql'], 'SELECT COUNT(*) FROM email_fila WHERE enviado_em IS NOT NULL') && $sqlEmailSimular['params'] === [30], 'Simulação de email_fila deve contar enviados há mais de 30 dias.');
expect(str_contains($sqlEmailApagar['sql'], 'DELETE FROM email_fila WHERE enviado_em IS NOT NULL') && $sqlEmailApagar['params'] === [30], 'Execução de email_fila deve apagar enviados há mais de 30 dias.');

$propPdo = new ReflectionProperty(\App\Core\Database::class, 'pdo');
$anterior = $propPdo->getValue();
$executor = new ReflectionMethod(Retencao::class, 'chamados');
try {
    $pdo = new RetencaoTestePdo();
    $propPdo->setValue(null, $pdo);
    expect($executor->invoke(null, 24, false) === 1 && $pdo->commitado, 'Executor confirma retenção do lote.');
    $executados = array_column($pdo->consultas, 0);
    expect(count(array_filter($executados, static fn ($sql) => str_starts_with($sql, 'UPDATE chamado_satisfacao'))) === 1, 'Limpeza de satisfação realmente executada, além de constar no plano.');
    $pdo = new RetencaoTestePdo();
    $pdo->falharSatisfacao = true;
    $propPdo->setValue(null, $pdo);
    try { $executor->invoke(null, 24, false); throw new LogicException('Deveria falhar'); }
    catch (RuntimeException $e) { expect($e->getMessage() === 'Falha de limpeza simulada', 'Falha real do executor propagada.'); }
    expect($pdo->revertido && !$pdo->commitado, 'Falha na limpeza reverte a transação.');
    expect(!array_filter($pdo->consultas, static fn ($q) => str_starts_with($q[0], 'UPDATE chamados SET')), 'Não marca anonimização quando a satisfação não foi limpa.');
} finally { $propPdo->setValue(null, $anterior); }

echo "Retencao de chamados e email_fila: prazos, anonimização, preservação estatística e executor transacional simulado OK.\n";
