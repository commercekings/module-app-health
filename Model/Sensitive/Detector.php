<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

/**
 * Finds sensitive data in a log entry's raw text by the rules in force (Rules): each rule's matches, confirmed by
 * its validator, counted - at least the rule's "min" in one entry to count. Only the rule keys and counts come out;
 * a match never leaves this class. The first MAX_TEXT bytes of an entry are scanned, under RegexGuard's
 * backtracking limit; a rule that hits it is skipped for that entry.
 */
class Detector
{
    public const MAX_TEXT = 16384;

    public function __construct(
        private readonly Rules $rules,
        private readonly ValidatorPool $validators
    ) {
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
                // patterns passed RegexGuard: they compile; false = the backtracking limit hit
                if (!preg_match_all($rule['pattern'], $text, $matches)) {
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

    /** @return array<string, array<string, mixed>> the rules in force by key (labels, severity, hint) */
    public function rules(): array
    {
        return array_column($this->rules->all(), null, 'key');
    }
}
