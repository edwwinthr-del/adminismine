<?php

namespace App\Services;

use App\Models\Master;
use App\Models\User;

/**
 * Masters may only touch the worksites they are assigned to. Users holding the
 * wider office permission (`attendance.approve`) — and users who are not masters
 * at all — are unrestricted.
 */
class MasterAccessService
{
    public function masterFor(User $user): ?Master
    {
        return Master::query()->active()->where('user_id', $user->id)->first();
    }

    /**
     * Worksite ids the user may act on, or null when unrestricted.
     *
     * @return list<int>|null
     */
    public function accessibleWorksiteIds(User $user): ?array
    {
        if ($user->can('attendance.approve')) {
            return null;
        }

        $master = $this->masterFor($user);

        if ($master === null) {
            return null;
        }

        return $master->worksites()->pluck('worksites.id')->all();
    }

    public function canActOn(User $user, int $worksiteId): bool
    {
        $allowed = $this->accessibleWorksiteIds($user);

        return $allowed === null || in_array($worksiteId, $allowed, true);
    }

    public function assertCanActOn(User $user, int $worksiteId): void
    {
        abort_unless(
            $this->canActOn($user, $worksiteId),
            403,
            'This worksite is not assigned to you.',
        );
    }
}
