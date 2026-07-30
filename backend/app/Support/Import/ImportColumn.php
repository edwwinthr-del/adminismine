<?php

namespace App\Support\Import;

/**
 * One column of an import template.
 *
 * The same object is the source for three things that must never drift apart:
 * the header written into the generated file, the rule the uploaded cell is
 * validated against, and the help text shown next to it. Change a column here
 * and the template, the validation and the instructions all follow.
 */
final class ImportColumn
{
    /**
     * @param  string  $key  the field name written to the record
     * @param  string  $header  the column heading in the template
     * @param  'text'|'date'|'decimal'|'integer'|'enum'|'boolean'  $type
     * @param  list<string>  $values  allowed values, for `enum`
     * @param  string|null  $example  a filled-in cell, so the format is never guessed
     */
    public function __construct(
        public readonly string $key,
        public readonly string $header,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly array $values = [],
        public readonly ?string $example = null,
        public readonly ?string $help = null,
    ) {}

    /** Laravel rules for one cell of this column. */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        return array_merge($rules, match ($this->type) {
            'date' => ['date'],
            'decimal' => ['numeric'],
            'integer' => ['integer'],
            'boolean' => ['boolean'],
            'enum' => ['string', 'in:'.implode(',', $this->values)],
            default => ['string', 'max:1000'],
        });
    }

    /** The one-line description of the cell's format shown in the instructions. */
    public function format(): string
    {
        return match ($this->type) {
            'date' => 'Date (YYYY-MM-DD, or any date Excel recognises)',
            'decimal' => 'Number, e.g. 1234.56',
            'integer' => 'Whole number',
            'boolean' => 'yes / no',
            'enum' => 'One of: '.implode(', ', $this->values),
            default => 'Text',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'header' => $this->header,
            'type' => $this->type,
            'required' => $this->required,
            'values' => $this->values,
            'example' => $this->example,
            'help' => $this->help,
            'format' => $this->format(),
        ];
    }
}
