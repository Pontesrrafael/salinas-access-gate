<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;
use RuntimeException;

final class InfleetSincronizacao
{
    public function __construct(private readonly Infleet $api = new Infleet()) {}

    public function executar(): array
    {
        $this->api->verificar();
        $lock = (string) Database::value("SELECT CONCAT(DATABASE(), ':infleet')");
        if ((int) Database::value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new RuntimeException('Já existe uma sincronização Infleet em andamento.');
        }
        try {
            // Só altera o banco após receber as duas listagens completas.
            $veiculos = $this->api->veiculos();
            $motoristas = $this->api->motoristas();
            return Database::transaction(function () use ($veiculos, $motoristas): array {
                $agora = date('Y-m-d H:i:s');
                $pendencias = 0;
                Database::run('UPDATE infleet_veiculos SET disponivel = 0');
                Database::run('UPDATE infleet_motoristas SET disponivel = 0');
                $cpfCounts = [];
                foreach ($motoristas as $m) {
                    $cpf = Crypto::cpfDigits(is_string($m['cpf'] ?? null) ? $m['cpf'] : null);
                    if (Crypto::cpfValido($cpf)) {
                        $hash = Crypto::cpfHash($cpf);
                        $cpfCounts[$hash] = ($cpfCounts[$hash] ?? 0) + 1;
                    }
                }
                foreach ($veiculos as $v) {
                    $pendencias += $this->veiculo($v, $agora) ? 0 : 1;
                }
                foreach ($motoristas as $m) {
                    $cpf = Crypto::cpfDigits(is_string($m['cpf'] ?? null) ? $m['cpf'] : null);
                    $hash = Crypto::cpfValido($cpf) ? Crypto::cpfHash($cpf) : null;
                    $pendencias += $this->motorista($m, $agora, $hash !== null && ($cpfCounts[$hash] ?? 0) > 1) ? 0 : 1;
                }
                $r = ['veiculos' => count($veiculos), 'motoristas' => count($motoristas), 'pendencias' => $pendencias];
                $id = Uuid::v4();
                Database::insert('infleet_sincronizacoes', ['id' => $id, ...$r]);
                Audit::log('infleet', $id, 'SINCRONIZACAO', $r);
                return $r;
            });
        } finally {
            Database::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function veiculo(array $v, string $agora): bool
    {
        $id = self::texto($v['id'] ?? null, 100, true);
        $placaOriginal = self::texto($v['plate'] ?? null, 100, true);
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placaOriginal) ?? '');
        $modelo = trim(implode(' ', array_filter([self::texto($v['brand'] ?? null, 60), self::texto($v['model'] ?? null, 60)])));
        $odometro = $v['odometer'] ?? null;
        if ($odometro !== null && (!is_numeric($odometro) || !is_finite((float) $odometro) || (float) $odometro < 0 || (float) $odometro >= 1e12)) {
            throw new RuntimeException('Odômetro Infleet inválido. Sincronização desfeita.');
        }
        $anterior = Database::one('SELECT * FROM infleet_veiculos WHERE id = ?', [$id]);
        $local = $anterior && $anterior['veiculo_id'] ? Database::one('SELECT * FROM veiculos WHERE id = ?', [$anterior['veiculo_id']]) : null;
        if ($local && $local['placa'] !== $placa) {
            throw new RuntimeException('Um veículo vinculado mudou de placa na Infleet. Confira o cadastro local antes de sincronizar.');
        }
        if (!$local && preg_match('/^[A-Z0-9]{5,10}$/', $placa)) {
            $local = Database::one('SELECT * FROM veiculos WHERE placa = ?', [$placa]);
            if ($local && Database::value('SELECT 1 FROM infleet_veiculos WHERE veiculo_id = ? AND id <> ?', [$local['id'], $id])) {
                $local = null; // Duas identidades externas com a mesma placa: exige conferência.
            } elseif (!$local) {
                $local = ['id' => Uuid::v4()];
                $tipo = self::tipoLocal((string) ($v['type'] ?? ''));
                // Tipo desconhecido fica pendente, sem assumir que um equipamento é um carro.
                if ($tipo !== null) {
                    Database::insert('veiculos', ['id' => $local['id'], 'placa' => $placa, 'tipo' => $tipo, 'marca_modelo' => $modelo ?: null, 'ativo' => 0]);
                    Audit::log('veiculos', $local['id'], 'IMPORTACAO_INFLEET', ['infleet_id' => $id, 'ativo' => 0]);
                } else {
                    $local = null;
                }
            }
        }
        if ($local && $modelo !== '') {
            if (isset($local['marca_modelo']) && $local['marca_modelo'] !== $modelo) {
                Audit::log('veiculos', $local['id'], 'ATUALIZACAO_INFLEET', ['campo' => 'marca_modelo', 'antes' => $local['marca_modelo'], 'depois' => $modelo]);
            }
            Database::update('veiculos', ['marca_modelo' => $modelo], 'id = ?', [$local['id']]);
        }
        $data = [
            'id' => $id, 'veiculo_id' => $local['id'] ?? null, 'placa' => $placaOriginal,
            'marca_modelo' => $modelo ?: null, 'tipo' => self::texto($v['type'] ?? null, 40),
            'odometro' => $odometro, 'ativo' => empty($v['deactivatedAt']) && empty($v['disabledAt']) ? 1 : 0,
            'motorista_id' => self::texto($v['driverId'] ?? null, 100), 'sincronizado_em' => $agora, 'disponivel' => 1,
        ];
        self::salvar('infleet_veiculos', $data);
        return $local !== null;
    }

    private function motorista(array $m, string $agora, bool $duplicado): bool
    {
        $id = self::texto($m['id'] ?? null, 100, true);
        $cpf = Crypto::cpfDigits(self::texto($m['cpf'] ?? null, 30));
        $valido = Crypto::cpfValido($cpf);
        $hash = $valido ? Crypto::cpfHash($cpf) : null;
        $anterior = Database::one('SELECT * FROM infleet_motoristas WHERE id = ?', [$id]);
        if ($duplicado && !empty($anterior['pessoa_id'])) {
            throw new RuntimeException('CPF de um motorista vinculado está duplicado na Infleet. Confira a origem antes de sincronizar.');
        }
        $pessoa = $hash && !$duplicado ? Database::one('SELECT id FROM pessoas WHERE cpf_hash = ?', [$hash]) : null;
        if ($anterior && $anterior['pessoa_id'] && ($anterior['cpf_hash'] !== $hash || $pessoa === null || $pessoa['id'] !== $anterior['pessoa_id'])) {
            throw new RuntimeException('A identidade de um motorista vinculado mudou. Confira o CPF antes de sincronizar.');
        }
        if ($pessoa && Database::value('SELECT 1 FROM infleet_motoristas WHERE pessoa_id = ? AND id <> ?', [$pessoa['id'], $id])) {
            $pessoa = null;
        }
        self::salvar('infleet_motoristas', [
            'id' => $id, 'pessoa_id' => $pessoa['id'] ?? null,
            'nome' => self::texto($m['name'] ?? null, 200, true),
            'cpf_enc' => $valido ? (($anterior['cpf_hash'] ?? null) === $hash ? $anterior['cpf_enc'] : Crypto::encryptCpf($cpf)) : null,
            'cpf_hash' => $hash, 'ativo' => ($m['active'] ?? false) === true ? 1 : 0,
            'veiculo_infleet_id' => self::texto($m['vehicle']['id'] ?? null, 100), 'sincronizado_em' => $agora, 'disponivel' => 1,
        ]);
        // Preserva categoria, nome local conferido, documentos, SSMA e ativo de pessoas existentes.
        return $pessoa !== null;
    }

    /** Conferência explícita do operador: cria pessoa ou associa pelo CPF exato. */
    public static function cadastrarMotorista(string $id, string $categoria, ?string $empresa): string
    {
        if (!in_array($categoria, ['colaborador', 'terceiro', 'visitante'], true)) {
            throw new RuntimeException('Escolha a categoria da pessoa para concluir a conferência.');
        }
        return Database::transaction(function () use ($id, $categoria, $empresa): string {
            $m = Database::one('SELECT * FROM infleet_motoristas WHERE id = ? FOR UPDATE', [$id]);
            if (!$m || !$m['cpf_hash'] || !$m['cpf_enc'] || !$m['ativo'] || !$m['disponivel']) {
                throw new RuntimeException('Motorista inexistente, inativo ou sem CPF válido. Corrija a origem antes de cadastrar.');
            }
            if ($m['pessoa_id']) { return $m['pessoa_id']; }
            if (Database::value('SELECT 1 FROM infleet_motoristas WHERE cpf_hash = ? AND id <> ?', [$m['cpf_hash'], $id])) {
                throw new RuntimeException('CPF duplicado entre motoristas Infleet. Confira os cadastros na origem.');
            }
            $pessoaId = Database::value('SELECT id FROM pessoas WHERE cpf_hash = ?', [$m['cpf_hash']]);
            if ($pessoaId === null) {
                $pessoaId = Uuid::v4();
                Database::insert('pessoas', [
                    'id' => $pessoaId, 'nome' => $m['nome'], 'cpf_enc' => $m['cpf_enc'], 'cpf_hash' => $m['cpf_hash'],
                    'categoria' => $categoria, 'empresa' => $empresa, 'funcao' => 'Motorista', 'ativo' => 0,
                ]);
                Audit::log('pessoas', $pessoaId, 'IMPORTACAO_INFLEET', ['infleet_id' => $id, 'categoria' => $categoria, 'ativo' => 0]);
            }
            Database::update('infleet_motoristas', ['pessoa_id' => $pessoaId], 'id = ?', [$id]);
            Audit::log('infleet', null, 'VINCULO_MOTORISTA', ['infleet_id' => $id, 'pessoa_id' => $pessoaId]);
            return $pessoaId;
        });
    }

    private static function salvar(string $table, array $data): void
    {
        $id = $data['id'];
        if (Database::value('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id])) {
            unset($data['id']);
            Database::update($table, $data, 'id = ?', [$id]);
        } else {
            Database::insert($table, $data);
        }
    }

    private static function texto(mixed $value, int $max, bool $required = false): ?string
    {
        if ($value === null && !$required) { return null; }
        if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max) {
            throw new RuntimeException('Um campo Infleet está ausente ou excede o tamanho permitido. Sincronização desfeita.');
        }
        $text = trim($value);
        return $text === '' ? null : $text;
    }

    private static function tipoLocal(string $type): ?string
    {
        return match ($type) {
            'CAR' => 'carro', 'TRUCK', 'GARBAGE_COLLECTOR' => 'caminhao',
            'MOTORCYCLE' => 'moto', 'BUS', 'VAN' => 'onibus', 'EQUIPMENT', 'FORKLIFT' => 'maquina',
            default => null,
        };
    }
}
