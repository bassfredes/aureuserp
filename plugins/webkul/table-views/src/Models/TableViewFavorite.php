<?php

namespace Webkul\TableViews\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;

class TableViewFavorite extends Model
{
    /**
     * Fillable.
     *
     * @var array
     */
    protected $fillable = [
        'is_favorite',
        'view_type',
        'view_key',
        'filterable_type',
        'user_id',
    ];

    /**
     * Get the user that owns the saved filter.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        // Same forced-on-create/immutable-after guarantee as TableView —
        // user_id is never trusted from a caller-supplied argument
        // (#138 PR4 ola4A round 2 review).
        static::saving(function (self $favorite): void {
            if (! $favorite->exists) {
                $userId = Auth::id();

                if ($userId === null) {
                    throw new AuthorizationException('TableViewFavorite requires an authenticated user.');
                }

                $favorite->user_id = $userId;

                return;
            }

            $originalUserId = $favorite->getOriginal('user_id');

            if ($originalUserId !== null && (int) $originalUserId !== (int) $favorite->user_id) {
                throw new AuthorizationException('Changing the owner of this TableViewFavorite is forbidden.');
            }
        });
    }

    /**
     * The single server-side resolver for favoriting/unfavoriting: the
     * owner is always Auth::id() itself, never a caller-supplied argument,
     * so a favorite can never be created or toggled on another user's
     * behalf. For "saved" views (real TableView rows), the target must
     * additionally be visible to the actor (own or public) — a private view
     * belonging to someone else can never be favorited (#138 PR4 ola4A).
     */
    public static function toggleForOwnViewOrFail(
        string $viewType,
        int|string $viewKey,
        string $filterableType,
        bool $isFavorite,
    ): self {
        $userId = Auth::id();

        if ($userId === null) {
            throw new AuthorizationException('An authenticated user is required.');
        }

        if ($viewType === 'saved') {
            TableView::assertVisibleOrFail((int) $viewKey, $filterableType);
        }

        return static::updateOrCreate(
            [
                'view_type'       => $viewType,
                'view_key'        => $viewKey,
                'filterable_type' => $filterableType,
                'user_id'         => $userId,
            ],
            [
                'is_favorite' => $isFavorite,
            ]
        );
    }
}
