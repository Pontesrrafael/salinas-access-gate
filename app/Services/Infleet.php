<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Cliente de consulta. O token e as respostas brutas nunca são enviados ao navegador ou aos logs. */
final class Infleet
{
    private array $config;
    private ?Closure $transport;
    public const ENDPOINT = 'https://api.infleet.com.br/v1/graphql';

    public function __construct(?array $config = null, ?callable $transport = null)
    {
        $this->config = $config ?? App::config('infleet');
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function habilitada(): bool
    {
        return (bool) (App::config('infleet')['enabled'] ?? false);
    }

    public function verificar(): void
    {
        if (!($this->config['enabled'] ?? false)) {
            throw new RuntimeException('Integração Infleet desativada. Configure INFLEET_ENABLED no servidor.');
        }
        if (trim((string) ($this->config['token'] ?? '')) === '') {
            throw new RuntimeException('Token Infleet não configurado no servidor.');
        }
        if (preg_match('/[\r\n]/', (string) $this->config['token'])) {
            throw new RuntimeException('Token Infleet inválido.');
        }
        if (empty($this->config['organization_id'])) {
            throw new RuntimeException('Configure INFLEET_ORGANIZATION_ID para restringir a importação à organização da Salinas.');
        }
        if ($this->transport === null && !extension_loaded('curl')) {
            throw new RuntimeException('A integração Infleet requer a extensão PHP curl.');
        }
    }

    public function veiculos(): array
    {
        $rows = $this->paginar(<<<'GQL'
query VeiculosSalinas($filter: ListVehiclesFilterInput!, $limit: Int!, $offset: Int!) {
  total: countVehicles(filter: $filter)
  rows: listVehicles(filter: $filter, limit: $limit, offset: $offset) {
    id plate brand model type odometer organizationId deactivatedAt disabledAt driverId
  }
}
GQL, 'filter', ['organizationId' => $this->config['organization_id'] ?? '']);
        foreach ($rows as $row) {
            if (($row['organizationId'] ?? null) !== $this->config['organization_id']) {
                throw new RuntimeException('A Infleet retornou veículo de outra organização. Nenhum cadastro foi alterado.');
            }
        }
        return $rows;
    }

    public function motoristas(): array
    {
        $rows = $this->paginar(<<<'GQL'
query MotoristasSalinas($filters: ListDriversFilters!, $limit: Int!, $offset: Int!) {
  total: countDrivers(filters: $filters)
  rows: listDriversPaginated(filters: $filters, limit: $limit, offset: $offset) {
    id name active cpf organization { id } vehicle { id plate }
  }
}
GQL, 'filters', ['organizationIds' => [$this->config['organization_id'] ?? '']]);
        foreach ($rows as $row) {
            if (($row['organization']['id'] ?? null) !== $this->config['organization_id']) {
                throw new RuntimeException('A Infleet retornou motorista sem organização correspondente. Nenhum cadastro foi alterado.');
            }
        }
        return $rows;
    }

    private function paginar(string $query, string $filterName, array $filter): array
    {
        $this->verificar();
        $rows = [];
        $seen = [];
        $offset = 0;
        $pages = 0;
        $total = null;
        $max = max(100, min(50000, (int) ($this->config['max_records'] ?? 10000)));
        do {
            if (++$pages > 500) {
                throw new RuntimeException('A paginação Infleet excedeu o limite de segurança. Confira o tamanho da frota e o limite de página com o suporte.');
            }
            $data = $this->consultar($query, [$filterName => $filter, 'limit' => 100, 'offset' => $offset]);
            if (!isset($data['total'], $data['rows']) || !is_int($data['total']) || !is_array($data['rows']) || !array_is_list($data['rows'])) {
                throw new RuntimeException('Resposta de paginação Infleet inválida. Nenhum cadastro foi alterado.');
            }
            $total ??= $data['total'];
            if ($total < 0 || $total > $max || $data['total'] !== $total) {
                throw new RuntimeException('A frota mudou durante a leitura ou excedeu o limite de importação. Repita a sincronização após conferir o limite.');
            }
            if (!$data['rows'] && $offset < $total) {
                throw new RuntimeException('A Infleet retornou uma listagem incompleta. Nenhum cadastro foi alterado.');
            }
            foreach ($data['rows'] as $row) {
                if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === '' || isset($seen[$row['id']])) {
                    throw new RuntimeException('Registro Infleet inválido ou duplicado entre páginas. Repita a sincronização.');
                }
                $seen[$row['id']] = true;
                $rows[] = $row;
            }
            $offset += count($data['rows']); // Respeita também um limite menor imposto pelo servidor.
            if ($offset > $total) {
                throw new RuntimeException('Contagem e listagem Infleet divergentes. Repita a sincronização.');
            }
        } while ($offset < $total);
        return $rows;
    }

    /** Datas locais em America/Cuiaba -> intervalo explícito em UTC; máximo de 7 dias por consulta. */
    public static function periodo(string $inicio, string $fim): array
    {
        $tz = new DateTimeZone('America/Cuiaba');
        $dates = [];
        foreach ([$inicio, $fim] as $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
            if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
                throw new InvalidArgumentException('Informe datas e horários válidos.');
            }
            $dates[] = $date;
        }
        [$start, $end] = $dates;
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0 || $seconds > 7 * 86400) {
            throw new InvalidArgumentException('O período deve ter início anterior ao fim e no máximo 7 dias.');
        }
        $utc = new DateTimeZone('UTC');
        return ['startAt' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'endAt' => $end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')];
    }

    public function percursos(string $vehicleId, array $periodo): array
    {
        $this->verificar();
        $data = $this->consultar(<<<'GQL'
query PercursosSalinas($trips: ListVehicleTripsFilterInput!, $positions: ListVehiclePositionsFilterInput!) {
  trips(filter: $trips) {
    id startedAt finishedAt distanceTraveled driver { id name } vehicle { id plate }
  }
  listVehiclePositions(filter: $positions) { id latitude longitude fixTime valid }
}
GQL, ['trips' => ['vehicleId' => $vehicleId, 'fixTime' => $periodo], 'positions' => ['vehicleId' => $vehicleId, 'fixTime' => $periodo]]);
        if (!is_array($data['trips'] ?? null) || !is_array($data['listVehiclePositions'] ?? null)) {
            throw new RuntimeException('A Infleet não retornou viagens e posições no formato esperado.');
        }
        foreach ($data['trips'] as $trip) {
            if (!is_array($trip) || ($trip['vehicle']['id'] ?? null) !== $vehicleId || !is_string($trip['startedAt'] ?? null)) {
                throw new RuntimeException('A Infleet retornou viagem inválida ou de outro veículo.');
            }
        }
        if (count($data['listVehiclePositions']) > 50000) {
            throw new RuntimeException('Há muitas posições nesse intervalo. Consulte um período menor.');
        }
        $points = [];
        foreach ($data['listVehiclePositions'] as $p) {
            if (!is_array($p) || ($p['valid'] ?? false) !== true || !is_numeric($p['latitude'] ?? null) || !is_numeric($p['longitude'] ?? null)) {
                continue;
            }
            $lat = (float) $p['latitude'];
            $lon = (float) $p['longitude'];
            if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180 || !is_string($p['fixTime'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $p['fixTime'])) {
                continue;
            }
            try { $time = new DateTimeImmutable($p['fixTime']); } catch (\Exception) { continue; }
            if ($time < new DateTimeImmutable($periodo['startAt']) || $time > new DateTimeImmutable($periodo['endAt'])) {
                continue;
            }
            $points[] = ['latitude' => $lat, 'longitude' => $lon, 'fixTime' => $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        }
        usort($points, static fn ($a, $b) => strcmp($a['fixTime'], $b['fixTime']));
        return ['trips' => $data['trips'], 'positions' => $points];
    }

    private function consultar(string $query, array $variables): array
    {
        $this->verificar();
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR);
        if ($this->transport !== null) {
            $response = ($this->transport)($payload);
            $status = $response['status'];
            $body = $response['body'];
        } else {
            $curl = curl_init(self::ENDPOINT);
            $body = '';
            $overflow = false;
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $this->config['token']],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => max(5, min(60, (int) ($this->config['timeout'] ?? 20))),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                    if (strlen($body) + strlen($chunk) > 16 * 1024 * 1024) {
                        $overflow = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) {
                throw new RuntimeException($overflow ? 'Resposta Infleet muito grande. Reduza o período consultado.' : 'Não foi possível conectar à Infleet. Confira rede, certificados e disponibilidade.');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('A Infleet recusou o acesso. Confira o token e as permissões com o suporte.');
        }
        if ($status === 429) {
            throw new RuntimeException('Limite de consultas Infleet atingido. Aguarde antes de tentar novamente.');
        }
        if ($status !== 200) {
            throw new RuntimeException('Infleet indisponível (HTTP ' . (int) $status . ').');
        }
        try { $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) {
            throw new RuntimeException('Resposta JSON inválida da Infleet.');
        }
        if (!is_array($json) || !empty($json['errors']) || !is_array($json['data'] ?? null)) {
            throw new RuntimeException('A consulta GraphQL foi recusada ou retornou dados parciais. Confira o esquema e as permissões com a Infleet.');
        }
        return $json['data'];
    }
}
