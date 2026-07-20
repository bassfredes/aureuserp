<?php

namespace Webkul\TableViews\Models;

use Illuminate\Database\Eloquent\Model;
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

    /**
     * The single server-side resolver for favoriting/unfavoriting: user_id
     * is always the caller's $userId argument, never a value read from the
     * request, so a favorite can never be created or toggled on another
     * user's behalf. For "saved" views (real TableView rows), the target
     * must additionally be visible to the actor (own or public) — a
     * private view belonging to someone else can never be favorited
     * (#138, PR 4 ola 4A).
     */
    public static function toggleForOwnViewOrFail(
        string $viewType,
        int|string $viewKey,
        string $filterableType,
        int $userId,
        bool $isFavorite,
    ): self {
        if ($viewType === 'saved') {
            TableView::assertVisibleOrFail((int) $viewKey, $filterableType, $userId);
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
