<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\WebsiteCheckInterface;
use CommerceKings\AppHealth\Model\CheckPool;

/** A pool of scripted checks: each answers what the test set in $results (code => result or a \Throwable). */
trait Pool
{
    /** @var array<string, CheckResult|array<int, CheckResult>|\Throwable|\Closure> */
    private array $results = [];

    /** @var array<string, int> runs per code */
    private array $runs = [];

    /** @param array<string, string> $shopWide code => label; @param array<string, string> $perWebsite */
    private function pool(array $shopWide, array $perWebsite = []): CheckPool
    {
        $checks = [];
        foreach ($shopWide as $code => $label) {
            $checks[] = new class ($code, $label, $this) implements CheckInterface {
                public function __construct(private string $code, private string $label, private object $test)
                {
                }

                public function getCode(): string
                {
                    return $this->code;
                }

                public function getLabel(): string
                {
                    return $this->label;
                }

                public function run(): CheckResult
                {
                    return $this->test->answer($this->code);
                }
            };
        }
        foreach ($perWebsite as $code => $label) {
            $checks[] = new class ($code, $label, $this) implements WebsiteCheckInterface {
                public function __construct(private string $code, private string $label, private object $test)
                {
                }

                public function getCode(): string
                {
                    return $this->code;
                }

                public function getLabel(): string
                {
                    return $this->label;
                }

                public function runForWebsites(array $websiteIds): array
                {
                    return $this->test->answer($this->code, $websiteIds);
                }
            };
        }
        return new CheckPool($checks);
    }

    /**
     * @internal called by the scripted checks: the result set for the code (a closure is called first), ok by
     *     default - for a check per website, ok for each website asked
     * @param int[]|null $websiteIds
     */
    public function answer(string $code, ?array $websiteIds = null): mixed
    {
        $this->runs[$code] = ($this->runs[$code] ?? 0) + 1;
        $result = $this->results[$code] ?? null;
        if ($result instanceof \Closure) {
            $result = $result();
        }
        if ($result instanceof \Throwable) {
            throw $result;
        }
        return $result ?? ($websiteIds === null ? CheckResult::ok()
            : array_fill_keys($websiteIds, CheckResult::ok()));
    }
}
