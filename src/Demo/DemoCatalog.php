<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Demo;

/**
 * Fixed public objects used when no Space-Track account is configured.
 *
 * The orbital numbers are representative catalog values for the UI, not a
 * live element set. JWST carries ORBIT_REGIME=L2 because a period-based
 * Earth-orbit label does not describe the Sun-Earth L2 halo.
 */
final class DemoCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            self::object(25544, 'ISS (ZARYA)', '1998-067A', 'PAYLOAD', 'US', 92.68, 51.64, 421, 418, 'LEO', '1998-11-20', 'TTMTR'),
            self::object(20580, 'HUBBLE', '1990-037B', 'PAYLOAD', 'US', 95.42, 28.47, 540, 535, 'LEO', '1990-04-24', 'AFETR'),
            self::object(48274, 'TIANGONG', '2021-035A', 'PAYLOAD', 'CN', 92.20, 41.47, 395, 389, 'LEO', '2021-04-29', 'WSC'),
            self::object(50463, 'JWST', '2021-130A', 'PAYLOAD', 'US', null, null, null, null, 'L2', '2021-12-25', 'FRGUI'),
            self::object(39504, 'TDRS 12', '2014-004A', 'PAYLOAD', 'US', 1436.10, 7.00, 35793, 35786, 'GEO', '2014-01-24', 'AFETR'),
            self::object(51850, 'GOES 18', '2022-021A', 'PAYLOAD', 'US', 1436.10, 0.05, 35790, 35780, 'GEO', '2022-03-01', 'AFETR'),
            self::object(40105, 'GPS IIF-7', '2014-045A', 'PAYLOAD', 'US', 717.98, 55.00, 20220, 20181, 'MEO', '2014-08-02', 'AFETR'),
            self::object(38421, 'SL-16 DEB', '1987-074AF', 'DEBRIS', 'CIS', 91.40, 71.00, 340, 312, 'LEO', '1987-08-01', 'PKMTR'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decay(): array
    {
        return [[
            'NORAD_CAT_ID' => 7530,
            'OBJECT_NUMBER' => 7530,
            'OBJECT_NAME' => 'SKYLAB',
            'INTLDES' => '1973-027A',
            'OBJECT_ID' => '1973-027A',
            'OBJECT_TYPE' => 'PAYLOAD',
            'COUNTRY' => 'US',
            'RCS_SIZE' => 'LARGE',
            'MSG_EPOCH' => '1979-07-11',
            'DECAY_EPOCH' => '1979-07-11',
            'DECAY' => '1979-07-11',
            'SOURCE' => 'DEMO',
            'MSG_TYPE' => 'HISTORICAL',
            'PRECEDENCE' => 0,
            'ORBIT_REGIME' => 'LEO',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tip(): array
    {
        return [[
            'NORAD_CAT_ID' => 7530,
            'OBJECT_NAME' => 'SKYLAB',
            'INTLDES' => '1973-027A',
            'OBJECT_TYPE' => 'PAYLOAD',
            'COUNTRY' => 'US',
            'MSG_EPOCH' => '1979-07-11T00:00:00',
            'DECAY_EPOCH' => '1979-07-11T16:37:00',
            'LAT' => -43.0,
            'LON' => 95.0,
            'DIRECTION' => 'DEMO',
            'ORBIT_REGIME' => 'LEO',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function boxscore(): array
    {
        return [
            [
                'COUNTRY' => 'US',
                'ORBITAL_PAYLOAD_COUNT' => 5,
                'ORBITAL_ROCKET_BODY_COUNT' => 1,
                'ORBITAL_DEBRIS_COUNT' => 0,
                'ORBITAL_TOTAL_COUNT' => 6,
                'DECAYED_TOTAL_COUNT' => 1,
            ],
            [
                'COUNTRY' => 'CN',
                'ORBITAL_PAYLOAD_COUNT' => 1,
                'ORBITAL_ROCKET_BODY_COUNT' => 0,
                'ORBITAL_DEBRIS_COUNT' => 0,
                'ORBITAL_TOTAL_COUNT' => 1,
                'DECAYED_TOTAL_COUNT' => 0,
            ],
            [
                'COUNTRY' => 'CIS',
                'ORBITAL_PAYLOAD_COUNT' => 0,
                'ORBITAL_ROCKET_BODY_COUNT' => 0,
                'ORBITAL_DEBRIS_COUNT' => 1,
                'ORBITAL_TOTAL_COUNT' => 1,
                'DECAYED_TOTAL_COUNT' => 0,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function launchSites(): array
    {
        return [
            ['SITE_CODE' => 'AFETR', 'LAUNCH_SITE' => 'CAPE CANAVERAL'],
            ['SITE_CODE' => 'TTMTR', 'LAUNCH_SITE' => 'BAIKONUR'],
            ['SITE_CODE' => 'FRGUI', 'LAUNCH_SITE' => 'KOUROU'],
            ['SITE_CODE' => 'WSC', 'LAUNCH_SITE' => 'WENCHANG'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function announcements(): array
    {
        return [[
            'OBJECT_NAME' => 'DEMO NOTICE',
            'ANNOUNCEMENT_TEXT' => 'Demo mode is serving a fixed public catalog. No Space-Track account is configured.',
            'CREATED' => '2026-01-01',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forClass(string $class): array
    {
        return match ($class) {
            'decay' => self::decay(),
            'tip' => self::tip(),
            'boxscore' => self::boxscore(),
            'launch_site' => self::launchSites(),
            'announcement' => self::announcements(),
            'cdm_public' => [[
                'CDM_ID' => 'DEMO-1',
                'NORAD_CAT_ID' => 25544,
                'OBJECT_NAME' => 'ISS (ZARYA)',
                'OBJECT_TYPE' => 'PAYLOAD',
                'COUNTRY' => 'US',
                'TCA' => '2026-01-02T00:00:00',
                'ORBIT_REGIME' => 'LEO',
            ]],
            'satcat', 'satcat_change', 'satcat_debut', 'gp', 'gp_history', 'tle', 'tle_latest', 'tle_publish', 'omm' => self::catalog(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(
        int $norad,
        string $name,
        string $international,
        string $type,
        string $country,
        ?float $period,
        ?float $inclination,
        ?float $apogee,
        ?float $perigee,
        string $regime,
        string $launch,
        string $site,
    ): array {
        return [
            'NORAD_CAT_ID' => $norad,
            'OBJECT_NUMBER' => $norad,
            'OBJECT_NAME' => $name,
            'SATNAME' => $name,
            'OBJECT_TYPE' => $type,
            'COUNTRY' => $country,
            'COUNTRY_CODE' => $country,
            'INTLDES' => $international,
            'OBJECT_ID' => $international,
            'LAUNCH' => $launch,
            'LAUNCH_DATE' => $launch,
            'SITE' => $site,
            'DECAY' => null,
            'DECAY_DATE' => null,
            'PERIOD' => $period,
            'INCLINATION' => $inclination,
            'APOGEE' => $apogee,
            'PERIGEE' => $perigee,
            'APOAPSIS' => $apogee,
            'PERIAPSIS' => $perigee,
            'ECCENTRICITY' => 0.0001,
            'RCS_SIZE' => 'LARGE',
            'CURRENT' => 'Y',
            'ORBIT_REGIME' => $regime,
            'EPOCH' => '2026-01-01T00:00:00',
            'MEAN_MOTION' => $period !== null && $period > 0 ? round(1440 / $period, 8) : null,
        ];
    }
}
