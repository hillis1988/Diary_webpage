<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * What {@see ViewerAccessService::createViewer()} hands back on success.
 *
 * design.md's `createViewer` interface method returns `Result<UserId>`; this
 * carries the {@see InvitationToken} alongside the new account's id as well,
 * because this MVP has no outbound email - the owner has to be shown the
 * invitation link themselves (task 15.4's viewer management page) rather than
 * it being sent for them. The raw token appears nowhere else once this object is
 * used to render that link: the database holds only its hash.
 */
final class ViewerInvitation
{
    public function __construct(
        public readonly UserId $viewerId,
        public readonly InvitationToken $token,
    ) {
    }
}
