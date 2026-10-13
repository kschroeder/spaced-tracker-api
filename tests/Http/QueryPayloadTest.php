<?php

declare(strict_types=1);

namespace SpacedTracker\Api\Tests\Http;

use SpacedTracker\Api\Exception\ApiException;
use SpacedTracker\Api\Http\QueryPayload;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Query\Direction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryPayloadTest extends TestCase
{
    public function testAPayloadMapsQueryOptionsOntoTheSpaceTrackQuery(): void
    {
        $query = (new QueryPayload())->build([
            'request_class' => 'gp',
            'where' => [
                'NORAD_CAT_ID' => 25544,
                'OBJECT_NAME' => null,
                'COUNTRY' => ['US', 'CN'],
            ],
            'order_by' => 'EPOCH',
            'direction' => 'DESC',
            'limit' => '10',
            'offset' => 5,
            'format' => 'tle',
            'columns' => ['NORAD_CAT_ID', 'EPOCH'],
            'distinct' => true,
            'metadata' => true,
            'show_empty' => false,
            'favorites' => ['iss', 'hst'],
        ]);

        self::assertSame('gp', $query->className());
        self::assertSame('basicspacedata', $query->controller());
        self::assertSame('NORAD_CAT_ID', $query->criteria()[0]->field);
        self::assertSame('25544', $query->criteria()[0]->value);
        self::assertSame('null-val', $query->criteria()[1]->value);
        self::assertSame('US,CN', $query->criteria()[2]->value);
        self::assertSame('EPOCH', $query->orders()[0]->field);
        self::assertSame(Direction::Desc, $query->orders()[0]->direction);
        self::assertSame(10, $query->limitCount());
        self::assertSame(5, $query->offset());
        self::assertSame(OutputFormat::Tle, $query->outputFormat());
        self::assertSame(['NORAD_CAT_ID', 'EPOCH'], $query->selectedColumns());
        self::assertTrue($query->isDistinct());
        self::assertTrue($query->includesMetadata());
        self::assertFalse($query->showsEmpty());
        self::assertSame(['iss', 'hst'], $query->favoriteNames());
    }

    public function testOrderListsCustomClassesAndLooseFlagsFollowTheQueryRules(): void
    {
        $payload = new QueryPayload();
        $ordered = $payload->build([
            'class' => 'satcat',
            'where' => null,
            'order_by' => [
                'COUNTRY',
                ['field' => 'NORAD_CAT_ID', 'direction' => 'desc'],
            ],
            'distinct' => 'yes',
            'metadata' => 1,
            'show_empty' => true,
            'limit' => 10.9,
            'columns' => [],
            'favorites' => [],
        ]);
        $custom = $payload->build([
            'class' => 'future_class',
            'controller' => 'FileShare',
        ]);

        self::assertSame([], $ordered->criteria());
        self::assertSame('COUNTRY', $ordered->orders()[0]->field);
        self::assertSame(Direction::Asc, $ordered->orders()[0]->direction);
        self::assertSame(Direction::Desc, $ordered->orders()[1]->direction);
        self::assertFalse($ordered->isDistinct());
        self::assertFalse($ordered->includesMetadata());
        self::assertTrue($ordered->showsEmpty());
        self::assertSame(10, $ordered->limitCount());
        self::assertNull($ordered->offset());
        self::assertSame([], $ordered->selectedColumns());
        self::assertSame([], $ordered->favoriteNames());
        self::assertSame('future_class', $custom->className());
        self::assertSame('fileshare', $custom->controller());
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int|string> $loc
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadsAreUnprocessable(array $payload, array $loc, string $message): void
    {
        try {
            (new QueryPayload())->build($payload);
            self::fail($message);
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('Request validation failed.', $exception->getMessage());
            self::assertSame([[
                'loc' => $loc,
                'msg' => $message,
                'type' => 'value_error',
            ]], $exception->detail);
        }
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: list<int|string>, 2: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            [[], ['body', 'class'], 'class is required.'],
            [['class' => ''], ['body', 'class'], 'class is required.'],
            [['request_class' => ''], ['body', 'class'], 'class is required.'],
            [['class' => 'gp', 'controller' => 1], ['body', 'controller'], 'controller must be a string.'],
            [['class' => 'gp', 'controller' => ''], ['body', 'class'], 'Controller "" does not provide request class "gp".'],
            [['class' => 'future_class'], ['body', 'class'], 'Unknown Space-Track request class "future_class".'],
            [['class' => 'download'], ['body', 'class'], 'Request class "download" belongs to more than one controller. Pass the controller explicitly.'],
            [['class' => 'future-class', 'controller' => 'fileshare'], ['body', 'class'], 'Invalid request class.'],
            [['class' => 'future_class', 'controller' => 'not a controller'], ['body', 'class'], 'Invalid controller.'],
            [['class' => 'gp', 'where' => 'NORAD_CAT_ID'], ['body', 'where'], 'where must be an object.'],
            [['class' => 'gp', 'where' => [0 => 'x']], ['body', 'where'], 'where fields must be strings.'],
            [['class' => 'gp', 'where' => ['NORAD_CAT_ID' => new \stdClass()]], ['body', 'where', 'NORAD_CAT_ID'], 'Predicate values must be scalars, null, or a list.'],
            [['class' => 'gp', 'where' => ['bad-name' => 'ISS']], ['body', 'where', 'bad-name'], 'Invalid predicate field.'],
            [['class' => 'gp', 'order_by' => ''], ['body', 'order_by'], 'order_by must be a string or a list.'],
            [['class' => 'gp', 'order_by' => 12], ['body', 'order_by'], 'order_by must be a string or a list.'],
            [['class' => 'gp', 'order_by' => [[]]], ['body', 'order_by'], 'order_by entries need a field.'],
            [['class' => 'gp', 'order_by' => ['1bad']], ['body', 'order_by'], 'Invalid order field.'],
            [['class' => 'gp', 'order_by' => [['field' => '1bad', 'direction' => 'desc']]], ['body', 'order_by'], 'Invalid order field.'],
            [['class' => 'gp', 'limit' => 'many'], ['body', 'limit'], 'limit must be an integer.'],
            [['class' => 'gp', 'limit' => 10001], ['body', 'limit'], 'limit cannot exceed 10000.'],
            [['class' => 'gp', 'limit' => 0], ['body', 'limit'], 'Limit must be at least 1.'],
            [['class' => 'gp', 'limit' => 10, 'offset' => 'no'], ['body', 'offset'], 'offset must be an integer.'],
            [['class' => 'gp', 'limit' => 10, 'offset' => -1], ['body', 'limit'], 'Offset cannot be negative.'],
            [['class' => 'gp', 'format' => 1], ['body', 'format'], 'format must be a string.'],
            [['class' => 'gp', 'format' => 'png'], ['body', 'format'], 'Unknown format.'],
            [['class' => 'gp', 'columns' => 'NORAD_CAT_ID'], ['body', 'columns'], 'columns must be a list.'],
            [['class' => 'gp', 'columns' => [1]], ['body', 'columns'], 'columns must be strings.'],
            [['class' => 'gp', 'columns' => ['bad-name']], ['body', 'columns'], 'Invalid column.'],
            [['class' => 'gp', 'favorites' => 'iss'], ['body', 'favorites'], 'favorites must be a list.'],
            [['class' => 'gp', 'favorites' => [1]], ['body', 'favorites'], 'favorites must be strings.'],
            [['class' => 'gp', 'favorites' => ['a/b']], ['body', 'favorites'], 'Favorite names cannot be empty or contain a slash or question mark.'],
        ];
    }
}
