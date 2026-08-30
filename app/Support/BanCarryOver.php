<?php

namespace App\Support;

use App\Models\BannedSub;
use App\Models\ChatBan;
use App\Models\Timeout;
use App\Models\User;

/**
 * Carries a chat ban or timeout across an account being deleted and remade.
 *
 * A sanction hangs off `user_id`, and every foreign key to `users` cascades, so
 * deleting an account clears whatever was standing against it. The identity provider's
 * `sub` is the same on the way back in, so it is what the sanction is parked against
 * for the gap: `hold()` on the way out, `claim()` when that `sub` next signs in.
 *
 * Deliberately a holding pen and not a second ban list. Nothing reads `banned_subs` to
 * decide whether somebody may chat - the moment the account exists again the sanction
 * is an ordinary row in `chat_bans` or `timeouts`, which is where every existing check
 * and every moderator already looks. A lifted ban is lifted once.
 */
class BanCarryOver
{
    /**
     * Park whatever is currently silencing this account against its `sub`.
     *
     * An account with no `sub` - one made by hand in /manage - has no identity to come
     * back as, so there is nothing to hold.
     */
    public static function hold(User $user): void
    {
        if (! $user->sub) {
            return;
        }

        if ($ban = $user->activeChatBan()) {
            BannedSub::updateOrCreate(
                ['sub' => $user->sub, 'kind' => BannedSub::KIND_BAN],
                ['reason' => $ban->reason, 'expires_at' => $ban->expires_at],
            );
        }

        if ($timeout = $user->activeTimeout()) {
            BannedSub::updateOrCreate(
                ['sub' => $user->sub, 'kind' => BannedSub::KIND_TIMEOUT],
                ['reason' => $timeout->reason, 'expires_at' => $timeout->expires_at],
            );
        }
    }

    /**
     * Put a parked sanction back on a freshly created account and clear the pen.
     *
     * The row is consumed either way: one that expired while the account did not exist
     * has nothing left to serve, and one that is put back is now an ordinary ban.
     */
    public static function claim(User $user): void
    {
        if (! $user->sub) {
            return;
        }

        $held = BannedSub::where('sub', $user->sub)->get();

        foreach ($held as $row) {
            if ($row->isExpired()) {
                $row->delete();

                continue;
            }

            if ($row->kind === BannedSub::KIND_BAN) {
                ChatBan::create([
                    'user_id' => $user->id,
                    'reason' => $row->reason,
                    'expires_at' => $row->expires_at,
                ]);
            }

            if ($row->kind === BannedSub::KIND_TIMEOUT) {
                Timeout::create([
                    'user_id' => $user->id,
                    'reason' => $row->reason,
                    'expires_at' => $row->expires_at,
                ]);
            }

            $row->delete();
        }
    }
}
