<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Http;

use SpacedTracker\Api\Http\ContactNormalizer;
use PHPUnit\Framework\TestCase;

final class ContactNormalizerTest extends TestCase
{
    public function testRowsFallBackThroughNamesDesignationsAndOrbitElements(): void
    {
        $normalizer = new ContactNormalizer();
        $historical = $normalizer->one([
            'OBJECT_NUMBER' => 1,
            'SATNAME' => 'OLD',
            'OBJECT_ID' => '1990-001A',
            'COUNTRY_CODE' => 'US',
            'OBJECT_TYPE' => 'ROCKET BODY',
            'CURRENT' => 'N',
            'PERIAPSIS' => 200.5,
            'PERIOD' => 92.0,
        ]);
        $site = $normalizer->one([
            'LAUNCH_SITE' => 'KOUROU',
            'SITE_CODE' => 'FRGUI',
            'MSG_TYPE' => 'HISTORICAL',
        ]);
        $decayed = $normalizer->one([
            'NORAD_CAT_ID' => 7530,
            'OBJECT_NAME' => 'SKYLAB',
            'DECAY_EPOCH' => '1979-07-11',
            'ORBIT_REGIME' => 'leo',
            'OBJECT_TYPE' => 'tba',
        ]);
        $heo = $normalizer->one([
            'CDM_ID' => 'DEMO-1',
            'PERIOD' => 700,
            'ECCENTRICITY' => 0.7,
            'APOAPSIS' => 40000,
        ]);
        $empty = $normalizer->one([]);

        self::assertSame('1', $historical['id']);
        self::assertSame('OLD', $historical['name']);
        self::assertSame('1990-001A', $historical['designation']);
        self::assertSame('R/B', $historical['class']);
        self::assertSame('LEO', $historical['orbit']);
        self::assertSame(200.5, $historical['perigee']);
        self::assertSame('US', $historical['country']);
        self::assertSame('HISTORICAL', $historical['status']);

        self::assertSame('FRGUI', $site['id']);
        self::assertSame('KOUROU', $site['name']);
        self::assertSame('', $site['class']);
        self::assertSame('UNKNOWN', $site['orbit']);
        self::assertSame('HISTORICAL', $site['status']);

        self::assertSame('DECAYED', $decayed['status']);
        self::assertSame('LEO', $decayed['orbit']);
        self::assertSame('TBA', $decayed['class']);
        self::assertSame('GEO', $normalizer->orbitOf([
            'PERIOD' => 1436,
            'ECCENTRICITY' => 0,
            'APOAPSIS' => 35790,
        ]));

        self::assertSame('DEMO-1', $heo['id']);
        self::assertSame('HEO', $heo['orbit']);
        self::assertSame('', $heo['status']);

        self::assertSame('', $empty['id']);
        self::assertSame('', $empty['name']);
        self::assertSame('', $empty['class']);
        self::assertSame('UNKNOWN', $empty['orbit']);
        self::assertSame('', $empty['status']);
        self::assertSame(['1', 'FRGUI'], array_column($normalizer->normalize([
            ['OBJECT_NUMBER' => 1, 'SATNAME' => 'OLD'],
            ['SITE_CODE' => 'FRGUI', 'LAUNCH_SITE' => 'KOUROU'],
        ]), 'id'));
    }
}
