<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Http;

use SpacedTracker\Model\OrbitRegime;
use SpacedTracker\Model\Row;

/**
 * Flattens a Space-Track row into the catalog table used by the UI.
 */
final class ContactNormalizer
{
    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function normalize(array $records): array
    {
        $contacts = [];
        foreach ($records as $record) {
            $contacts[] = $this->one($record);
        }

        return $contacts;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function orbitOf(array $row): string
    {
        $explicit = Row::string($row, 'ORBIT_REGIME');
        if ($explicit !== null && $explicit !== '') {
            return strtoupper($explicit);
        }

        return OrbitRegime::fromElements(
            Row::float($row, 'PERIOD'),
            Row::float($row, 'ECCENTRICITY'),
            Row::float($row, 'APOGEE') ?? Row::float($row, 'APOAPSIS'),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function one(array $row): array
    {
        $norad = Row::int($row, 'NORAD_CAT_ID') ?? Row::int($row, 'OBJECT_NUMBER');
        $name = Row::string($row, 'OBJECT_NAME')
            ?? Row::string($row, 'SATNAME')
            ?? Row::string($row, 'LAUNCH_SITE')
            ?? Row::string($row, 'ANNOUNCEMENT_TEXT');
        $designation = Row::string($row, 'INTLDES')
            ?? Row::string($row, 'OBJECT_ID')
            ?? Row::string($row, 'SITE_CODE')
            ?? Row::string($row, 'CDM_ID');
        $country = Row::string($row, 'COUNTRY') ?? Row::string($row, 'COUNTRY_CODE');
        $perigee = Row::float($row, 'PERIGEE') ?? Row::float($row, 'PERIAPSIS');

        return [
            'id' => $norad !== null ? (string) $norad : ($designation ?? $country ?? ''),
            'name' => $name ?? '',
            'designation' => $designation ?? '',
            'class' => $this->objectClass(Row::string($row, 'OBJECT_TYPE')),
            'orbit' => $this->orbitOf($row),
            'perigee' => $perigee,
            'country' => $country ?? '',
            'status' => $this->status($row),
        ];
    }

    private function objectClass(?string $type): string
    {
        return match (strtoupper(trim((string) $type))) {
            'PAYLOAD' => 'PAY',
            'ROCKET BODY' => 'R/B',
            'DEBRIS' => 'DEB',
            '' => '',
            default => strtoupper((string) $type),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function status(array $row): string
    {
        $decay = Row::string($row, 'DECAY') ?? Row::string($row, 'DECAY_DATE') ?? Row::string($row, 'DECAY_EPOCH');
        if ($decay !== null && $decay !== '') {
            return 'DECAYED';
        }
        $current = Row::string($row, 'CURRENT');
        if ($current === 'N') {
            return 'HISTORICAL';
        }
        if ($current === null && Row::string($row, 'MSG_TYPE') !== null) {
            return (string) Row::string($row, 'MSG_TYPE');
        }

        return $current === null && Row::int($row, 'NORAD_CAT_ID') === null ? '' : 'ON ORBIT';
    }
}
