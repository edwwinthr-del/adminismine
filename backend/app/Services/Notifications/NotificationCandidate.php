<?php

namespace App\Services\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing worth telling somebody about, before it is known who gets told.
 * `data` holds only language-neutral params (names, numbers, dates) — the
 * wording lives in the frontend dictionaries.
 */
final class NotificationCandidate
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $key  distinguishes candidates sharing a subject (e.g. one
     *                            per expiring document field), or stands in for the
     *                            subject when there is none
     */
    public function __construct(
        public readonly ?Model $subject = null,
        public readonly array $data = [],
        public readonly ?Carbon $dueDate = null,
        public readonly ?float $amount = null,
        public readonly ?string $key = null,
    ) {}

    /** Stable identity of the underlying issue, within its type. */
    public function identity(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        return $this->subject === null
            ? 'general'
            : class_basename($this->subject).':'.$this->subject->getKey();
    }
}
