<?php

namespace App\Services\Import;

use App\Support\Import\CellValue;
use App\Support\Import\ImportColumn;
use App\Support\Import\ImportEntity;
use Illuminate\Support\Facades\Validator;

/**
 * Reads a filled-in template back.
 *
 * Unlike the workbook parsers, which know one sheet's fixed shape, this one
 * works from the header row: columns may be reordered or dropped and the file
 * still reads correctly, which is what makes a downloaded template survive
 * being edited in the wild.
 *
 * Every row is validated against the entity's own rules before it can be
 * approved. An invalid row is not dropped — it is kept with its errors attached
 * and defaulted to `skip`, so a bad file imports its good rows and reports the
 * rest instead of failing whole.
 */
class TemplateSheetReader
{
    /**
     * Rows past this are not read. A template this long is a sign the file is
     * the wrong shape, and the preview has to stay something a person can look at.
     */
    public const MAX_ROWS = 5000;

    /**
     * @param  list<array<int, mixed>>  $rows  zero-indexed cell arrays, in sheet order
     * @return array{rows: list<ParsedRow>, missing_columns: list<string>, unknown_columns: list<string>}
     */
    public function read(ImportEntity $entity, array $rows): array
    {
        $header = $rows[TemplateGenerator::HEADER_ROW - 1] ?? [];
        [$positions, $unknown] = $this->mapHeader($entity, $header);

        $present = array_values($positions);
        $missing = array_values(array_map(
            fn (ImportColumn $column): string => $column->header,
            array_filter(
                $entity->columns,
                fn (ImportColumn $column): bool => $column->required && ! in_array($column->key, $present, true),
            ),
        ));

        // A file missing a required column cannot be judged row by row: say so
        // once, rather than repeating the same error on every line.
        if ($missing !== []) {
            return ['rows' => [], 'missing_columns' => $missing, 'unknown_columns' => $unknown];
        }

        $parsed = [];

        foreach ($rows as $index => $cells) {
            $rowNumber = $index + 1;

            if ($rowNumber <= TemplateGenerator::HEADER_ROW || CellValue::isBlank($cells)) {
                continue;
            }

            if (count($parsed) >= self::MAX_ROWS) {
                break;
            }

            $parsed[] = $this->readRow($entity, $cells, $rowNumber, $positions);
        }

        return ['rows' => $parsed, 'missing_columns' => [], 'unknown_columns' => $unknown];
    }

    /**
     * @param  array<int, string>  $positions  column index → field name
     */
    private function readRow(ImportEntity $entity, array $cells, int $rowNumber, array $positions): ParsedRow
    {
        $columns = $entity->byKey();
        $raw = [];
        $mapped = [];

        foreach ($positions as $index => $key) {
            $value = $cells[$index] ?? null;
            // Kept exactly as read: the original text of a record is never
            // rewritten, only mapped alongside (rule 4).
            $raw[$columns[$key]->header] = is_scalar($value) ? $value : null;
            $mapped[$key] = $this->coerce($columns[$key], $value);
        }

        $row = new ParsedRow(
            target: $entity->target,
            rowNumber: $rowNumber,
            raw: $raw,
            mapped: array_filter($mapped, fn ($value): bool => $value !== null),
        );

        return $this->validate($entity, $row, $mapped);
    }

    /** Cast a cell to the column's type, tolerating the formats Excel produces. */
    private function coerce(ImportColumn $column, mixed $value): mixed
    {
        return match ($column->type) {
            'date' => CellValue::date($value),
            'decimal' => CellValue::number($value),
            'integer' => ($number = CellValue::number($value)) === null ? null : (int) $number,
            'boolean' => $this->boolean($value),
            'enum' => $this->enum($column, $value),
            default => CellValue::string($value),
        };
    }

    /** Accepts the words a person actually types, in all three UI languages. */
    private function boolean(mixed $value): ?bool
    {
        $text = CellValue::key($value);

        if ($text === null) {
            return null;
        }

        return match ($text) {
            'YES', 'Y', 'TRUE', '1', 'DA', 'EVET' => true,
            'NO', 'N', 'FALSE', '0', 'NE', 'HAYIR' => false,
            default => null,
        };
    }

    /** Enum values are matched case-insensitively but stored exactly as declared. */
    private function enum(ImportColumn $column, mixed $value): ?string
    {
        $text = CellValue::string($value);

        if ($text === null) {
            return null;
        }

        foreach ($column->values as $allowed) {
            if (mb_strtolower($allowed) === mb_strtolower($text)) {
                return $allowed;
            }
        }

        // Returned unchanged so validation can report what was actually written.
        return $text;
    }

    /** @param array<string, mixed> $mapped */
    private function validate(ImportEntity $entity, ParsedRow $row, array $mapped): ParsedRow
    {
        $columns = $entity->byKey();
        $validator = Validator::make($mapped, $entity->rules(), [], $this->attributeNames($entity));

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $row->withIssue('invalid', $message, ['field' => $field]);
            }
        }

        // A cell that could not be read at all is a separate, clearer complaint
        // than "the field is required" — the user wrote something, in a shape
        // this column does not take. Columns absent from the file are skipped:
        // there is no cell to complain about.
        foreach ($columns as $key => $column) {
            if (($mapped[$key] ?? null) === null && CellValue::string($row->raw[$column->header] ?? null) !== null) {
                $row->withIssue(
                    'invalid',
                    "{$column->header}: '{$row->raw[$column->header]}' is not a valid value. {$column->format()}.",
                    ['field' => $key],
                );
            }
        }

        return $row;
    }

    /** @return array<string, string> */
    private function attributeNames(ImportEntity $entity): array
    {
        $names = [];

        foreach ($entity->columns as $column) {
            $names[$column->key] = $column->header;
        }

        return $names;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array{0: array<int, string>, 1: list<string>} positions, unrecognised headings
     */
    private function mapHeader(ImportEntity $entity, array $header): array
    {
        $known = $entity->headerMap();
        $positions = [];
        $unknown = [];

        foreach ($header as $index => $cell) {
            $text = CellValue::string($cell);

            if ($text === null) {
                continue;
            }

            $key = $known[ImportEntity::normalizeHeader($text)] ?? null;

            if ($key === null) {
                $unknown[] = $text;

                continue;
            }

            // First occurrence wins, so a column repeated further right cannot
            // shadow the one the user actually filled in.
            if (in_array($key, $positions, true)) {
                continue;
            }

            $positions[$index] = $key;
        }

        return [$positions, $unknown];
    }
}
