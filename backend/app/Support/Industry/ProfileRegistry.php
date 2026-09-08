<?php

namespace App\Support\Industry;

/**
 * Every industry profile the app ships with.
 *
 * Same shape as `ReportRegistry`, `LookupRegistry` and the rest: adding one
 * means adding a class and listing it here, and nothing else in the app changes.
 *
 * There are three on purpose. Three is enough to prove the abstraction — one
 * that is the app's own defaults and two that differ on modules, hierarchy
 * depth, working week, wording and lists. A fourth written before a real company
 * asks for it is a guess, and the guesses are what turn a product into a
 * settings screen nobody understands.
 */
final class ProfileRegistry
{
    /**
     * The profiles that describe a company somebody actually runs.
     *
     * `mining` is this app's own install restated, so it is observed by
     * definition. The other two are reasoned from the same domain model and have
     * never met a construction firm or a labour agency — their module sets,
     * working week and wording are defensible guesses, and the first real
     * customer of either should be expected to correct them.
     *
     * The screen says so rather than presenting all three with equal
     * confidence, because a customer who trusts a guessed default and discovers
     * it was wrong a month later has been misled by the product.
     *
     * @var list<string>
     */
    public const VALIDATED = ['mining'];

    /** @var array<string, IndustryProfile>|null */
    private ?array $profiles = null;

    public static function isValidated(string $key): bool
    {
        return in_array($key, self::VALIDATED, true);
    }

    /** @return array<string, IndustryProfile> */
    public function all(): array
    {
        return $this->profiles ??= collect($this->build())
            ->keyBy(fn (IndustryProfile $profile): string => $profile->key())
            ->all();
    }

    public function find(string $key): ?IndustryProfile
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return list<IndustryProfile> */
    private function build(): array
    {
        return [
            new MiningProfile,
            new ConstructionProfile,
            new LabourServicesProfile,
        ];
    }
}
