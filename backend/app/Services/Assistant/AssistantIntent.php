<?php

namespace App\Services\Assistant;

use App\Reports\ReportRegistry;

/**
 * The contract between the model and the app. The assistant does not get to run
 * arbitrary queries — it picks one of these intents, and Laravel decides whether
 * the user is allowed it and what it actually does.
 *
 * Read intents are answered straight away. `create_record` never writes: it
 * becomes a suggestion the user has to confirm.
 */
class AssistantIntent
{
    public const READ = ['report', 'dashboard', 'search', 'answer'];

    public const WRITE = ['create_record'];

    /** Targets the assistant may propose creating. Deliberately narrow. */
    public const CREATE_TARGETS = [
        'utility_bill',
        'payable_invoice',
        'bank_transaction',
        'worker_need',
        'travel_expense',
    ];

    public const SEARCH_ENTITIES = ['supplier', 'client', 'employee', 'house'];

    public static function all(): array
    {
        return array_merge(self::READ, self::WRITE);
    }

    public static function isWrite(string $intent): bool
    {
        return in_array($intent, self::WRITE, true);
    }

    /**
     * The instruction the model is given. It names the intents and the exact
     * shape of the reply, so the response can be validated rather than trusted.
     */
    public static function systemPrompt(ReportRegistry $registry, array $availableReports, string $today): string
    {
        $reports = implode(', ', $availableReports);
        $targets = implode(', ', self::CREATE_TARGETS);
        $entities = implode(', ', self::SEARCH_ENTITIES);

        return <<<PROMPT
        You are the assistant inside AdminisMine's finance app. Today is {$today}.

        Reply with JSON only, matching exactly one of these shapes:

        1. Run a report to answer a question about records:
           {"intent":"report","report_key":"<one of: {$reports}>","filters":{"month":"YYYY-MM","year":2026,"date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD","supplier_id":1,"client_id":1,"employee_id":1,"worksite_id":1},"reply":"<one short sentence>"}

        2. Company overview for a month (balances, unpaid totals, alerts):
           {"intent":"dashboard","filters":{"month":"YYYY-MM"},"reply":"<one short sentence>"}

        3. Find a party by name:
           {"intent":"search","entity":"<one of: {$entities}>","query":"<text>","reply":"<one short sentence>"}

        4. Nothing to look up — just answer:
           {"intent":"answer","reply":"<your answer>"}

        5. Propose creating a record (never saved without the user confirming):
           {"intent":"create_record","target":"<one of: {$targets}>","fields":{...},"reply":"<one short sentence describing what you propose>"}

        Rules:
        - Include only the filters you actually need; omit the rest.
        - Never invent amounts, dates or names. If the user has not given a value
          you need for create_record, leave it out and say what is missing.
        - You cannot change or delete anything. You may only propose creating.
        - Keep "reply" short and factual, in the language the user wrote in.
        PROMPT;
    }
}
