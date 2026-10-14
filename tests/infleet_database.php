<?php
declare(strict_types=1);

// Executar somente em banco descartável já migrado. Recusa qualquer banco da aplicação real.
if (getenv('INFLEET_TEST_DB') !== '1' || getenv('DB_DATABASE') !== 'sag_infleet_test' || is_file(dirname(__DIR__) . '/.env')) {
    fwrite(STDERR, "Use INFLEET_TEST_DB=1 e DB_DATABASE=sag_infleet_test em uma cópia sem .env e um banco descartável migrado.\n");
    exit(1);
}
putenv('CPF_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('CPF_INDEX_KEY=' . base64_encode(random_bytes(32)));
require __DIR__ . '/infleet.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;
use App\Services\Infleet;
use App\Services\InfleetSincronizacao;

function syntheticCpf(): string
{
    $cpf = (string) random_int(100000000, 999999999);
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) { $sum += (int) $cpf[$i] * ($t + 1 - $i); }
        $cpf .= (string) (((10 * $sum) % 11) % 10);
    }
    return $cpf;
}
$tag = substr(str_replace('-', '', Uuid::v4()), 0, 8);
$plate1 = 'T' . strtoupper(substr($tag, 0, 6));
$plate2 = 'N' . strtoupper(substr($tag, 0, 6));
$localVehicle = Uuid::v4();
$localPerson = Uuid::v4();
$cpf1 = syntheticCpf();
$cpf2 = syntheticCpf();
Database::insert('veiculos', ['id' => $localVehicle, 'placa' => $plate1, 'tipo' => 'carro', 'marca_modelo' => 'Modelo anterior', 'cor' => 'Prata', 'ativo' => 0, 'comissionado_mina' => 1]);
Database::insert('pessoas', ['id' => $localPerson, 'nome' => 'Nome conferido localmente', 'categoria' => 'terceiro', 'ativo' => 0, 'cpf_enc' => Crypto::encryptCpf($cpf1), 'cpf_hash' => Crypto::cpfHash($cpf1)]);
$v1 = 'test-v1-' . $tag;
$v2 = 'test-v2-' . $tag;
$d1 = 'test-d1-' . $tag;
$d2 = 'test-d2-' . $tag;
$d3 = 'test-d3-' . $tag;
$vehicles = [
    ['id' => $v1, 'plate' => $plate1, 'brand' => 'Marca', 'model' => 'Modelo novo', 'type' => 'CAR', 'odometer' => 15000.125, 'organizationId' => 'org-teste'],
    ['id' => $v2, 'plate' => $plate2, 'brand' => 'Marca', 'model' => 'Caminhão', 'type' => 'TRUCK', 'odometer' => 0, 'organizationId' => 'org-teste'],
];
$drivers = [
    ['id' => $d1, 'name' => 'Nome remoto', 'active' => true, 'cpf' => $cpf1, 'organization' => ['id' => 'org-teste']],
    ['id' => $d2, 'name' => 'Motorista novo fictício', 'active' => true, 'cpf' => $cpf2, 'organization' => ['id' => 'org-teste']],
    ['id' => $d3, 'name' => 'Sem identidade válida', 'active' => true, 'cpf' => '00000000000', 'organization' => ['id' => 'org-teste']],
];
$transport = function (string $payload) use (&$vehicles, &$drivers): array {
    $request = json_decode($payload, true);
    $rows = str_contains($request['query'], 'VeiculosSalinas') ? $vehicles : $drivers;
    return fakeResponse(['total' => count($rows), 'rows' => array_slice($rows, $request['variables']['offset'], 100)]);
};
$sync = new InfleetSincronizacao(new Infleet($config, $transport));
$r = $sync->executar();
check($r === ['veiculos' => 2, 'motoristas' => 3, 'pendencias' => 2], 'Resumo da sincronização incorreto.');
$v = Database::one('SELECT * FROM veiculos WHERE id = ?', [$localVehicle]);
check($v['cor'] === 'Prata' && (int) $v['ativo'] === 0 && (int) $v['comissionado_mina'] === 1, 'Alterou cor, ativo ou comissionamento locais.');
check($v['marca_modelo'] === 'Marca Modelo novo', 'Modelo não sincronizado.');
check((int) Database::value('SELECT ativo FROM veiculos WHERE placa = ?', [$plate2]) === 0, 'Veículo novo foi ativado automaticamente.');
check((string) Database::value('SELECT odometro FROM infleet_veiculos WHERE id = ?', [$v2]) === '0.000', 'Odômetro zero virou ausência de leitura.');
check(Database::value('SELECT pessoa_id FROM infleet_motoristas WHERE id = ?', [$d1]) === $localPerson, 'Motorista não foi associado pelo CPF.');
check(Database::value('SELECT nome FROM pessoas WHERE id = ?', [$localPerson]) === 'Nome conferido localmente', 'Sobrescreveu nome local conferido.');
check(Database::value('SELECT cpf_enc FROM infleet_motoristas WHERE id = ?', [$d2]) !== $cpf2, 'CPF armazenado em texto puro.');
check(Crypto::decryptCpf(Database::value('SELECT cpf_enc FROM infleet_motoristas WHERE id = ?', [$d2])) === $cpf2, 'CPF cifrado não pode ser recuperado.');
check(Database::value('SELECT cpf_hash FROM infleet_motoristas WHERE id = ?', [$d3]) === null, 'CPF inválido aceito para vínculo.');
$count = (int) Database::value('SELECT COUNT(*) FROM veiculos');
$sync->executar();
check((int) Database::value('SELECT COUNT(*) FROM veiculos') === $count, 'Sincronização repetida duplicou veículos.');
$newPerson = InfleetSincronizacao::cadastrarMotorista($d2, 'terceiro', 'Empresa teste');
check((int) Database::value('SELECT ativo FROM pessoas WHERE id = ?', [$newPerson]) === 0, 'Pessoa nova foi ativada automaticamente.');
check(InfleetSincronizacao::cadastrarMotorista($d2, 'terceiro', 'Empresa teste') === $newPerson, 'Conferência repetida duplicou pessoa.');
rejects(fn () => InfleetSincronizacao::cadastrarMotorista($d3, 'terceiro', null), 'Permitiu cadastrar CPF inválido.');
$drivers[1]['cpf'] = syntheticCpf();
$beforeRuns = (int) Database::value('SELECT COUNT(*) FROM infleet_sincronizacoes');
rejects(fn () => $sync->executar(), 'Reassociou identidade de motorista sem conferência.');
check((int) Database::value('SELECT COUNT(*) FROM infleet_sincronizacoes') === $beforeRuns, 'Persistiu execução que deveria ser desfeita.');
$drivers[1]['cpf'] = $cpf2;
$drivers[] = ['id' => 'test-duplicado-' . $tag, 'name' => 'Duplicidade fictícia', 'active' => true, 'cpf' => $cpf1, 'organization' => ['id' => 'org-teste']];
rejects(fn () => $sync->executar(), 'Aceitou CPF duplicado de motorista vinculado.');
array_pop($drivers);
$vehicles[0]['odometer'] = 777;
$vehicles[1]['odometer'] = -1;
rejects(fn () => $sync->executar(), 'Aceitou odômetro negativo.');
check((string) Database::value('SELECT odometro FROM infleet_veiculos WHERE id = ?', [$v1]) === '15000.125', 'Rollback não reverteu primeiro veículo.');
check((int) Database::value('SELECT disponivel FROM infleet_motoristas WHERE id = ?', [$d1]) === 1, 'Rollback deixou cadastro indisponível.');
$vehicles = [];
$drivers = [];
$sync->executar();
check((int) Database::value('SELECT disponivel FROM infleet_veiculos WHERE id = ?', [$v1]) === 0, 'Ausência na última leitura não foi sinalizada.');
check(Database::value('SELECT id FROM veiculos WHERE id = ?', [$localVehicle]) === $localVehicle, 'Ausência na Infleet excluiu cadastro local.');
echo "Infleet com MariaDB: {$checks} verificações acumuladas passaram; dados exclusivamente fictícios.\n";
