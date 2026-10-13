<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Demo;

use SpacedTracker\Api\Demo\DemoClient;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Operator;
use SpacedTracker\Query\Query;
use SpacedTracker\Query\QueryResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class DemoClientTest extends TestCase
{
    public function testUnusedCatalogSetsAreServedWithoutASocket(): void
    {
        $client = new DemoClient();

        $decay = $client->execute(Query::create(RequestClass::Decay));
        self::assertSame('SKYLAB', $decay->records[0]['OBJECT_NAME']);
        self::assertSame(7530, $decay->records[0]['NORAD_CAT_ID']);
        self::assertSame('DEMO', $decay->records[0]['SOURCE']);

        $tip = $client->execute(Query::create(RequestClass::Tip));
        self::assertSame(-43.0, $tip->records[0]['LAT']);
        self::assertSame(95.0, $tip->records[0]['LON']);
        self::assertSame('DEMO', $tip->records[0]['DIRECTION']);

        $boxscore = $client->execute(Query::create(RequestClass::Boxscore));
        self::assertSame(['US', 'CN', 'CIS'], array_column($boxscore->records, 'COUNTRY'));
        self::assertSame(1, $boxscore->records[2]['ORBITAL_DEBRIS_COUNT']);

        $sites = $client->execute(Query::create(RequestClass::LaunchSite));
        self::assertSame('KOUROU', $sites->records[2]['LAUNCH_SITE']);
        self::assertCount(4, $sites->records);

        $announcements = $client->execute(Query::create(RequestClass::Announcement));
        self::assertSame('DEMO NOTICE', $announcements->records[0]['OBJECT_NAME']);
        self::assertStringContainsString('No Space-Track account', $announcements->records[0]['ANNOUNCEMENT_TEXT']);

        $cdm = $client->execute(Query::create(RequestClass::CdmPublic));
        self::assertSame('DEMO-1', $cdm->records[0]['CDM_ID']);
        self::assertSame(25544, $cdm->records[0]['NORAD_CAT_ID']);

        foreach ([RequestClass::GpHistory, RequestClass::Tle, RequestClass::TleLatest, RequestClass::TlePublish, RequestClass::Omm, RequestClass::SatcatChange, RequestClass::SatcatDebut] as $class) {
            $catalog = $client->execute(Query::create($class));
            self::assertCount(8, $catalog->records, $class->value);
            self::assertSame('ISS (ZARYA)', $catalog->records[0]['OBJECT_NAME']);
        }

        $unknown = $client->execute(Query::custom('future_class', 'fileshare'));
        self::assertSame([], $unknown->records);
    }

    public function testFiltersSortPageAndKeepMetadataForTheFixedCatalog(): void
    {
        $client = new DemoClient();
        $satcat = Query::create(RequestClass::Satcat);

        self::assertSame([25544], $this->norad($client->execute($satcat->where('NORAD_CAT_ID', 25544))));
        self::assertSame([25544], $this->norad($client->execute($satcat->where('OBJECT_NAME', 'iss (zarya)'))));
        self::assertSame([20580], $this->norad($client->execute($satcat->where('OBJECT_NAME', Operator::like('hub')))));
        self::assertSame([40105], $this->norad($client->execute($satcat->where('OBJECT_NAME', Operator::startsWith('gps')))));
        self::assertSame([38421], $this->norad($client->execute($satcat->where('OBJECT_TYPE', Operator::notEqual('PAYLOAD')))));
        self::assertSame([50463], $this->norad($client->execute($satcat->where('PERIOD', Operator::isNull()))));
        self::assertSame([39504, 51850], $this->norad($client->execute($satcat->where('PERIOD', Operator::greaterThan(1000)))));
        self::assertSame([48274, 38421], $this->norad($client->execute($satcat->where('PERIOD', '<=92.5'))));
        self::assertSame([39504, 51850], $this->norad($client->execute($satcat->where('APOGEE', '>30000'))));
        self::assertSame([38421], $this->norad($client->execute($satcat->where('PERIGEE', '<350'))));
        self::assertSame([40105], $this->norad($client->execute($satcat->where('PERIOD', Operator::between(700, 800)))));
        self::assertSame([48274, 38421], $this->norad($client->execute($satcat->where('COUNTRY', Operator::notEqual('US')))));
        self::assertCount(7, $client->execute($satcat->where('COUNTRY', ' cis , us '))->records);
        self::assertSame([], $client->execute($satcat->where('OBJECT_NAME', Operator::like('zzz')))->records);
        self::assertSame([], $client->execute($satcat->where('NORAD_CAT_ID', '>=999999'))->records);
        self::assertSame([], $client->execute($satcat->where('OBJECT_NAME', '>A'))->records);

        $page = $client->execute(
            $satcat->orderBy('COUNTRY', Direction::Asc)
                ->orderBy('NORAD_CAT_ID', Direction::Desc)
                ->limit(1),
        );
        self::assertSame(38421, $page->records[0]['NORAD_CAT_ID']);

        $tied = $client->execute($satcat->orderBy('PERIOD', Direction::Asc));
        $periods = array_column($tied->records, 'PERIOD');
        $numbers = array_values(array_filter($periods, static fn (mixed $period): bool => is_numeric($period)));
        $ordered = $numbers;
        sort($ordered, SORT_NUMERIC);
        self::assertSame($ordered, $numbers);
        self::assertNull($periods[0]);
        self::assertSame([25544], $this->norad($client->execute($satcat->where('PERIOD', 92.68))));

        $window = $client->execute($satcat->withMetadata()->limit(2, 1));
        self::assertSame([20580, 48274], $this->norad($window));
        self::assertSame(8, $window->metadata['Total'] ?? null);
        self::assertSame(2, $window->metadata['Limit'] ?? null);

        $many = $client->executeMany([
            'decay' => Query::create(RequestClass::Decay),
            'sites' => Query::create(RequestClass::LaunchSite),
        ]);
        self::assertSame('SKYLAB', $many['decay']->records[0]['OBJECT_NAME']);
        self::assertCount(4, $many['sites']->records);
    }

    public function testSessionUploadModelAndHostChecksStayInsideTheProcess(): void
    {
        $client = new DemoClient();
        self::assertTrue($client->hasSession());
        self::assertSame('2026-01-01T02:00:00+00:00', $client->sessionExpiresAt()?->format(\DATE_ATOM));
        $client->logout();
        self::assertFalse($client->hasSession());
        self::assertNull($client->sessionExpiresAt());
        $client->login();
        self::assertTrue($client->hasSession());

        $uploaded = $client->uploadFile('folder_1', 'a/b.txt', 'ignored', 'text/plain');
        self::assertSame(201, $uploaded->statusCode);
        self::assertSame('{"ok":true,"demo":true}', $uploaded->rawBody);
        self::assertFalse($uploaded->query->showsEmpty());
        try {
            $client->uploadFile('bad id', 'notes.txt', 'ignored');
            self::fail('A folder id with a space must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Invalid folder id.', $exception->getMessage());
        }
        foreach (['', '.', '..', "\0", '/'] as $filename) {
            try {
                $client->uploadFile('folder_1', $filename, 'ignored');
                self::fail('Filename ' . json_encode($filename) . ' must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Invalid upload filename.', $exception->getMessage());
            }
        }

        $definition = $client->modelDefinition('gp');
        self::assertSame('basicspacedata', $definition['controller']);
        self::assertSame('gp', $definition['class']);
        self::assertSame('NORAD_CAT_ID', $definition['data'][0]['Field']);
        $fallback = $client->modelDefinition('not_a_class', 'custom');
        self::assertSame('custom', $fallback['controller']);
        self::assertSame('not_a_class', $fallback['class']);
        $download = $client->modelDefinition('download', 'publicfiles');
        self::assertSame('publicfiles', $download['controller']);
        foreach ([null, ''] as $controller) {
            try {
                $client->modelDefinition('not_a_class', $controller);
                self::fail('An unknown class without a controller must throw.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Unknown', $exception->getMessage());
            }
        }

        $factory = new Psr17Factory();
        $relative = $client->sendAuthenticated($factory->createRequest('GET', '/basicspacedata/query/class/gp'));
        $official = $client->sendAuthenticated($factory->createRequest('GET', 'https://WWW.SPACE-TRACK.ORG/basicspacedata'));
        $apex = $client->sendAuthenticated($factory->createRequest('GET', 'https://space-track.org/basicspacedata'));
        self::assertSame('{"demo":true}', (string) $relative->getBody());
        self::assertSame(200, $official->getStatusCode());
        self::assertSame(200, $apex->getStatusCode());
        try {
            $client->sendAuthenticated($factory->createRequest('GET', 'https://evil.example/basicspacedata'));
            self::fail('A foreign host must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('different host', $exception->getMessage());
        }
    }

    /**
     * @return list<int>
     */
    private function norad(QueryResult $result): array
    {
        $ids = [];
        foreach ($result->records as $record) {
            $ids[] = $record['NORAD_CAT_ID'];
        }

        return $ids;
    }
}
