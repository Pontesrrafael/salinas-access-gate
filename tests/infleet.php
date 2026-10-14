<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Infleet;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function rejects(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException | InvalidArgumentException $e) {
        check(!str_contains($e->getMessage(), 'TOKEN_FICTICIO'), 'Token exposto na mensagem de erro.');
        return;
    }
    throw new RuntimeException($message);
}
function fakeResponse(array $data): array
{
    return ['status' => 200, 'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
}
$config = ['enabled' => true, 'token' => 'TOKEN_FICTICIO', 'organization_id' => 'org-teste'];
$vehicle = ['id' => 'v1', 'organizationId' => 'org-teste'];
$offsets = [];
$api = new Infleet($config, function ($payload) use (&$offsets, $vehicle): array {
    $v = json_decode($payload, true)['variables'];
    check($v['filter']['organizationId'] === 'org-teste', 'Filtro de organização ausente.');
    $offsets[] = $v['offset'];
    return fakeResponse(['total' => 2, 'rows' => $v['offset'] === 0 ? [$vehicle] : [['id' => 'v2', 'organizationId' => 'org-teste']]]);
});
check(count($api->veiculos()) === 2 && $offsets === [0, 1], 'Não paginou com o limite efetivo do servidor.');
rejects(fn () => (new Infleet($config, fn () => fakeResponse(['total' => 2, 'rows' => [$vehicle]])))->veiculos(), 'Aceitou IDs duplicados entre páginas.');
rejects(fn () => (new Infleet($config, fn () => fakeResponse(['total' => 1, 'rows' => []])))->veiculos(), 'Aceitou uma página incompleta.');
rejects(fn () => (new Infleet($config, fn () => fakeResponse(['total' => 1, 'rows' => [['id' => 'v1', 'organizationId' => 'outra']]])))->veiculos(), 'Aceitou outra organização.');
foreach ([401, 403, 429, 500] as $status) {
    rejects(fn () => (new Infleet($config, fn () => ['status' => $status, 'body' => 'TOKEN_FICTICIO']))->veiculos(), 'Aceitou erro HTTP.');
}
rejects(fn () => (new Infleet($config, fn () => ['status' => 200, 'body' => '{']))->veiculos(), 'Aceitou JSON inválido.');
rejects(fn () => (new Infleet($config, fn () => ['status' => 200, 'body' => '{"data":{"rows":[]},"errors":[{"message":"TOKEN_FICTICIO"}]}']))->veiculos(), 'Aceitou erro GraphQL com dados parciais.');
rejects(fn () => (new Infleet([...$config, 'enabled' => false], fn () => throw new RuntimeException('Não deveria conectar')))->veiculos(), 'Conectou com integração desativada.');
rejects(fn () => (new Infleet([...$config, 'token' => ''], fn () => []))->verificar(), 'Aceitou token vazio.');
rejects(fn () => (new Infleet([...$config, 'token' => "TOKEN_FICTICIO\r\nInjected: x"], fn () => []))->verificar(), 'Aceitou injeção de cabeçalho.');
rejects(fn () => (new Infleet([...$config, 'organization_id' => ''], fn () => []))->verificar(), 'Aceitou organização vazia.');
$period = Infleet::periodo('2026-10-14T00:00', '2026-10-14T01:00');
check($period === ['startAt' => '2026-10-14T04:00:00Z', 'endAt' => '2026-10-14T05:00:00Z'], 'Conversão Cuiabá/UTC incorreta.');
rejects(fn () => Infleet::periodo('2026-02-30T00:00', '2026-03-01T01:00'), 'Aceitou data inexistente.');
rejects(fn () => Infleet::periodo('2026-10-14T00:00', '2026-10-13T01:00'), 'Aceitou intervalo invertido.');
rejects(fn () => Infleet::periodo('2026-10-01T00:00', '2026-10-09T00:00'), 'Aceitou intervalo maior que 7 dias.');
$points = [
    ['valid' => true, 'latitude' => -15.5, 'longitude' => -56.1, 'fixTime' => '2026-10-14T00:30:00-04:00'],
    ['valid' => false, 'latitude' => -15.5, 'longitude' => -56.1, 'fixTime' => '2026-10-14T04:20:00Z'],
    ['valid' => true, 'latitude' => 120, 'longitude' => -56.1, 'fixTime' => '2026-10-14T04:20:00Z'],
    ['valid' => true, 'latitude' => -15.4, 'longitude' => -56.0, 'fixTime' => '2026-10-14T04:10:00Z'],
    ['valid' => true, 'latitude' => -15.4, 'longitude' => -56.0, 'fixTime' => '2026-10-13T04:10:00Z'],
];
$tripApi = new Infleet($config, function ($payload) use ($points, $period): array {
    $variables = json_decode($payload, true)['variables'];
    check($variables['trips']['fixTime'] === $period && $variables['positions']['vehicleId'] === 'v1', 'Filtros dos percursos incorretos.');
    return fakeResponse(['trips' => [], 'listVehiclePositions' => $points]);
});
$route = $tripApi->percursos('v1', $period);
check(count($route['positions']) === 2, 'Pontos inválidos ou fora do período não foram removidos.');
check($route['positions'][0]['fixTime'] === '2026-10-14T04:10:00Z', 'Pontos fora de ordem cronológica.');
$driverApi = new Infleet($config, function ($payload): array {
    check(json_decode($payload, true)['variables']['filters']['organizationIds'] === ['org-teste'], 'Motoristas sem filtro de organização.');
    return fakeResponse(['total' => 1, 'rows' => [['id' => 'd1', 'organization' => ['id' => 'org-teste']]]]);
});
check(count($driverApi->motoristas()) === 1, 'Motoristas não foram recebidos.');
echo "Infleet: {$checks} verificações passaram (respostas simuladas; sem acesso à API real).\n";
