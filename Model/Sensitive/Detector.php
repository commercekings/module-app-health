<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

/**
 * Finds sensitive data in a log entry's raw text by the rules in force (Rules): each rule's matches, confirmed by
 * its validator, counted - at least the rule's "min" in one entry to count. Only the rule keys and counts come out;
 * a match never leaves this class. The first MAX_TEXT bytes of an entry are scanned, under RegexGuard's
 * backtracking limit; a rule that hits it finds nothing in that entry and is counted (takeSlowRules(), logged by
 * LogReader). until() sets a deadline: past it the scan stops (wasCut()).
 */
class Detector
{
    public const MAX_TEXT = 16384;

    private ?float $deadline = null;
    private bool $cut = false;
    /** @var array<string, int> rule key => entries where it hit the backtracking limit */
    private array $slow = [];

    /** @var array<string, float> rule key => seconds spent since until() */
    private array $timings = [];
    private ?string $lastRule = null;
    private ?string $cutAfter = null;
    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(
        private readonly Rules $rules,
        private readonly ValidatorPool $validators,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    /** @return array<string, int> "<set>/<rule>" => matches */
    public function detect(string $text): array
    {
        $rules = $this->rules->all();
        if ($rules === [] || $text === '') {
            return [];
        }
        $text = substr($text, 0, self::MAX_TEXT);
        $found = [];
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string)RegexGuard::BACKTRACK_LIMIT);
        try {
            foreach ($rules as $rule) {
                if ($this->deadline !== null && ($this->clock)() > $this->deadline) {
                    $this->cut = true;
                    $this->cutAfter = $this->lastRule; // the rule running when the time ran out
                    break;
                }
                // patterns passed RegexGuard: they compile; false = the backtracking limit hit
                $started = ($this->clock)();
                $hits = preg_match_all($rule['pattern'], $text, $matches);
                $this->timings[$rule['key']] = ($this->timings[$rule['key']] ?? 0.0) + ($this->clock)() - $started;
                $this->lastRule = $rule['key'];
                if ($hits === false) {
                    $this->slow[$rule['key']] = ($this->slow[$rule['key']] ?? 0) + 1;
                    continue;
                }
                if ($hits === 0) {
                    continue;
                }
                $validator = $rule['validator'] !== null ? $this->validators->get($rule['validator']) : null;
                $count = $validator === null ? count($matches[0])
                    : count(array_filter($matches[0], fn ($match) => $validator->isValid((string)$match)));
                if ($count >= $rule['min']) {
                    $found[$rule['key']] = $count;
                }
                unset($matches);
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string)$limit);
        }
        return $found;
    }

    /** A time (microtime) after which detect() stops scanning, or null for none; resets wasCut(). */
    public function until(?float $deadline): void
    {
        $this->deadline = $deadline;
        $this->cut = false;
    }

    /**
     * Where the time went since until(): the rule that was running when a scan was cut (null: none cut) and the three
     * slowest rules in milliseconds; then starts over.
     *
     * @return array{rule: ?string, slowest: array<string, float>}
     */
    public function takeCut(): array
    {
        arsort($this->timings);
        $cut = ['rule' => $this->cutAfter, 'slowest' => array_map(fn (float $seconds) => round($seconds * 1000, 1),
            array_slice($this->timings, 0, 3, true))];
        [$this->timings, $this->cutAfter, $this->lastRule] = [[], null, null];
        return $cut;
    }

    /** Whether a scan stopped at the deadline since until(). */
    public function wasCut(): bool
    {
        return $this->cut;
    }

    /** @return array<string, int> the rules that hit the backtracking limit since the last call: in how many entries */
    public function takeSlowRules(): array
    {
        [$slow, $this->slow] = [$this->slow, []];
        return $slow;
    }

    /** @return array<string, array<string, mixed>> the rules in force by key (labels, severity, hint) */
    public function rules(): array
    {
        return array_column($this->rules->all(), null, 'key');
    }
}
