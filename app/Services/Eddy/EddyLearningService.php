<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyUserProfile;
use App\Models\User;

/**
 * The Phase 6 preference-learning loop. Every human approve/reject of an Eddy
 * proposal is a signal: it rolls up into the user's `frequently_used` tally and
 * feeds back into the chat envelope so Eddy weights its runner-up ordering toward
 * what THIS user actually sanctions. Repeated rejection of an action type
 * discourages it; repeated approval promotes it.
 *
 * The signal lives entirely in eddy.eddy_user_profiles — no new table — as
 * frequently_used = { action_type: { approved: n, rejected: n } }.
 */
class EddyLearningService
{
    /** Record a human decision on an Eddy-proposed action type. No-op when disabled. */
    public function recordDecision(User $user, string $actionType, string $decision): void
    {
        if (! (bool) config('eddy.learning.enabled')) {
            return;
        }
        if ($actionType === '' || ! in_array($decision, ['approved', 'rejected'], true)) {
            return;
        }

        $profile = EddyUserProfile::firstOrCreate(['user_id' => $user->id], ['frequently_used' => []]);

        $tally = $profile->frequently_used ?? [];
        $entry = $tally[$actionType] ?? ['approved' => 0, 'rejected' => 0];
        $entry[$decision] = (int) ($entry[$decision] ?? 0) + 1;
        $tally[$actionType] = $entry;

        $profile->frequently_used = $tally;
        $profile->learned_at = now();
        $profile->save();
    }

    /**
     * The user's learned action preferences for the chat envelope. Actions with a
     * positive net (approved − rejected) are preferred (highest first); negative net
     * are discouraged. Empty when learning is off or the user has no history.
     *
     * @return array{preferred_actions:array<int,string>, discouraged_actions:array<int,string>}|array{}
     */
    public function preferencesFor(User $user): array
    {
        if (! (bool) config('eddy.learning.enabled')) {
            return [];
        }

        $tally = EddyUserProfile::where('user_id', $user->id)->value('frequently_used') ?? [];
        if ($tally === []) {
            return [];
        }

        $scored = collect($tally)->map(fn (array $e, string $type): array => [
            'action_type' => $type,
            'net' => (int) ($e['approved'] ?? 0) - (int) ($e['rejected'] ?? 0),
        ]);

        return [
            'preferred_actions' => $scored->filter(fn (array $r): bool => $r['net'] > 0)
                ->sortByDesc('net')->pluck('action_type')->values()->all(),
            'discouraged_actions' => $scored->filter(fn (array $r): bool => $r['net'] < 0)
                ->sortBy('net')->pluck('action_type')->values()->all(),
        ];
    }
}
