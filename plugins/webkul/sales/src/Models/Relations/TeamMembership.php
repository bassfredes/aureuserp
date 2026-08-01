<?php

namespace Webkul\Sale\Models\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Sale\Models\TeamMember;

/**
 * Team::members() returns this instead of a plain BelongsToMany (#138
 * A4G). The TeamMember pivot's own model events already cover every path
 * Eloquent routes through the pivot class — attach(), detach() and
 * updateExistingPivot() all instantiate it once ->using() is wired. What
 * they cannot cover is the shape of a BULK call:
 *
 *  - Eloquent's sync() diffs, detaches, and only then attaches, with no
 *    wrapping transaction. A collection mixing allowed and forbidden
 *    users would therefore delete the legitimate memberships first and
 *    only fail on the offending attach, leaving the team stripped of
 *    members it should still have. This was accepted as a documented
 *    residual risk in A4F; here it is closed.
 *  - A partial attach() has the same problem in the other direction: the
 *    allowed ids of a mixed list get inserted before the rejected one
 *    aborts the call.
 *
 * So every bulk entry point validates the WHOLE set before mutating
 * anything, and then runs inside a transaction. The per-row guards in
 * TeamMember remain the authority; this class only guarantees that a
 * rejected set leaves no partial write behind.
 */
class TeamMembership extends BelongsToMany
{
    /**
     * Same normalization Eloquent itself applies before touching the
     * pivot table: parseIds() unwraps models/collections, and
     * formatRecordsList() moves an `id => [pivot attributes]` map's keys
     * back into position, which parseIds() alone does not do.
     *
     * @return list<int|string>
     */
    private function extractRelatedIds(mixed $ids): array
    {
        return array_keys($this->formatRecordsList($this->parseIds($ids)));
    }

    private function assertEveryMembershipIsAllowed(mixed $ids): void
    {
        foreach ($this->extractRelatedIds($ids) as $id) {
            TeamMember::assertMembershipIsAllowed(
                $this->parent->getKey() === null ? null : (int) $this->parent->getKey(),
                $id === null ? null : (int) $id,
            );
        }
    }

    /** {@inheritDoc} */
    public function attach($id, array $attributes = [], $touch = true)
    {
        $this->assertEveryMembershipIsAllowed($id);

        return $this->parent->getConnection()->transaction(
            fn () => parent::attach($id, $attributes, $touch)
        );
    }

    /** {@inheritDoc} */
    public function sync($ids, $detaching = true)
    {
        $this->assertEveryMembershipIsAllowed($ids);

        return $this->parent->getConnection()->transaction(
            fn () => parent::sync($ids, $detaching)
        );
    }

    /**
     * No pre-validation to add here (the pivot's own deleting guard
     * re-authorizes each row against the parent Team), but a bulk detach
     * still gets the same all-or-nothing guarantee as the other two.
     *
     * {@inheritDoc}
     */
    public function detach($ids = null, $touch = true)
    {
        return $this->parent->getConnection()->transaction(
            fn () => parent::detach($ids, $touch)
        );
    }
}
