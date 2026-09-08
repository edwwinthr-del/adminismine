<?php

namespace App\Support\Industry;

/**
 * A shape of company, as a bundle of the four things Phases 1–4 made settable.
 *
 * A profile is **code, not configuration**. That is the point: it can be
 * reviewed, versioned and run under the test suite, which sixty independent
 * settings cannot be. Applying one writes ordinary settings rows, so everything
 * it chose stays separately editable afterwards — a profile is a starting point,
 * never a mode the app runs in.
 *
 * There are three, and the fourth is written when a real company asks for it.
 * Guessing at how company #4 differs is how a product acquires settings nobody
 * uses and a test matrix nobody can run.
 */
interface IndustryProfile
{
    /** Stable identifier, stored on the settings row. */
    public function key(): string;

    /**
     * The modules this shape of company has.
     *
     * @return list<string> keys from App\Support\Modules
     */
    public function modules(): array;

    /**
     * Which levels of mine → project → worksite it uses.
     *
     * The database keeps all three whatever this returns; a company that does
     * not have deposits simply never creates one, and this is what stops the app
     * asking. Always includes `worksite` — that is where people clock in, and
     * every module that records anything points at it.
     *
     * @return list<string> any of: mine | project | worksite
     */
    public function workStructureLevels(): array;

    /**
     * Payroll and entitlement rules.
     *
     * @return array<string, mixed> a subset of the company_settings rule columns
     */
    public function rules(): array;

    /**
     * What this shape of company calls things.
     *
     * Keys must be on the terminology whitelist; values are per language, and a
     * language left out keeps the built-in wording rather than borrowing
     * another's.
     *
     * @return array<string, array<string, string>>
     */
    public function terminology(): array;

    /**
     * The open-ended lists, where this shape of company differs.
     *
     * A vocabulary listed here is replaced wholesale; one left out keeps what it
     * ships with. Shipped values are never deleted — they are deactivated if the
     * profile does not list them, because model defaults and the workbook
     * importer still name them.
     *
     * @return array<string, list<string>>
     */
    public function vocabularies(): array;
}
