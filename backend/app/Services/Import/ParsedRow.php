<?php

namespace App\Services\Import;

/**
 * One sheet row, read and mapped but not yet saved. Issues are advisory: a row
 * with issues is still shown in the preview and can still be imported — the
 * point is that the user sees the problem first.
 */
final class ParsedRow
{
    /**
     * @param  array<string, mixed>  $raw  cells exactly as read
     * @param  array<string, mixed>  $mapped  what would be written
     * @param  list<array{type: string, field?: string, message: string, record_id?: int}>  $issues
     */
    public function __construct(
        public readonly string $target,
        public readonly int $rowNumber,
        public readonly array $raw,
        public readonly array $mapped,
        public array $issues = [],
    ) {}

    public function withIssue(string $type, string $message, array $extra = []): self
    {
        $this->issues[] = array_merge(['type' => $type, 'message' => $message], $extra);

        return $this;
    }

    public function hasIssue(string $type): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['type'] === $type) {
                return true;
            }
        }

        return false;
    }
}
