<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\Stub;

/**
 * A database adapter stub for the checks: every Select call is recorded in $this->calls (method and arguments), the
 * fetch methods answer from queues of results, update() and the other writes are recorded too.
 */
trait Db
{
    /** @var array<int, array<int, mixed>> */
    private array $calls = [];

    /** @var array<int, array{0: mixed, 1: array<mixed>, 2: array<mixed>}> table, data, where of each update() */
    private array $updates = [];

    /** @param array<string, array<int, mixed>> $results fetch method => its answers in order */
    private function db(array $results = []): AdapterInterface&Stub
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group', 'limit', 'join', 'order', 'union', 'columns'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                $this->calls[] = [$method, ...$args];
                return $select;
            });
        }
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('quoteInto')->willReturnCallback(
            fn ($text, $value) => str_replace('?', "'" . (is_array($value) ? implode("','", $value) : $value) . "'",
                $text)
        );
        $db->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $updateAnswers = $results['update'] ?? [];
        unset($results['update']);
        $db->method('update')->willReturnCallback(function ($table, $data, $where) use (&$updateAnswers) {
            $this->updates[] = [$table, $data, $where];
            return $updateAnswers === [] ? 1 : array_shift($updateAnswers);
        });
        foreach ($results as $method => $answers) {
            $db->method($method)->willReturnOnConsecutiveCalls(...$answers);
        }
        return $db;
    }

    private function resource(AdapterInterface $db): ResourceConnection&Stub
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => (string)$name);
        return $resource;
    }

    /** @return array<int, mixed> the recorded where() conditions */
    private function wheres(): array
    {
        return array_values(array_map(fn ($call) => $call[1] ?? null,
            array_filter($this->calls, fn ($call) => $call[0] === 'where')));
    }
}
