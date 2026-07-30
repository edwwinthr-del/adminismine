<?php

namespace App\Support\Import;

/**
 * One thing a user can choose to import: a set of columns, the permission that
 * guards it, and the committer target its rows become.
 */
final class ImportEntity
{
    /** @param list<ImportColumn> $columns */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $target,
        public readonly string $permission,
        public readonly array $columns,
        public readonly ?string $description = null,
    ) {}

    /** @return array<string, ImportColumn> keyed by field name */
    public function byKey(): array
    {
        $indexed = [];

        foreach ($this->columns as $column) {
            $indexed[$column->key] = $column;
        }

        return $indexed;
    }

    /**
     * Header text → field name, matched on a normalized key so a template that
     * has been re-saved, re-cased or padded with spaces still lines up.
     *
     * Both the header and the field name are accepted, because a file exported
     * from somewhere else is more likely to carry the raw field name.
     *
     * @return array<string, string>
     */
    public function headerMap(): array
    {
        $map = [];

        foreach ($this->columns as $column) {
            $map[self::normalizeHeader($column->header)] = $column->key;
            $map[self::normalizeHeader($column->key)] = $column->key;
        }

        return $map;
    }

    public static function normalizeHeader(mixed $header): string
    {
        $text = preg_replace('/[\s_]+/u', ' ', trim((string) $header));

        return mb_strtolower($text ?? '');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->columns as $column) {
            $rules[$column->key] = $column->rules();
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'target' => $this->target,
            'description' => $this->description,
            'columns' => array_map(fn (ImportColumn $column): array => $column->toArray(), $this->columns),
        ];
    }
}
