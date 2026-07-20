<?php

namespace Webkul\TableViews\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\Security\Models\User;

class TableView extends Model
{
    protected $fillable = [
        'name',
        'icon',
        'color',
        'is_public',
        'filters',
        'filterable_type',
        'user_id',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        // user_id is never trusted from a caller-supplied argument — every
        // resolver below derives it from Auth::id() itself, and this hook is
        // the last line of defense: forced on create, immutable after
        // (#138 PR4 ola4A round 2 review — a $userId parameter on the public
        // resolvers let an authenticated user pass a DIFFERENT user's id and
        // have it trusted as the owner).
        static::saving(function (self $view): void {
            if (! $view->exists) {
                $userId = Auth::id();

                if ($userId === null) {
                    throw new AuthorizationException('TableView requires an authenticated user.');
                }

                $view->user_id = $userId;

                return;
            }

            $originalUserId = $view->getOriginal('user_id');

            if ($originalUserId !== null && (int) $originalUserId !== (int) $view->user_id) {
                throw new AuthorizationException('Changing the owner of this TableView is forbidden.');
            }
        });
    }

    /**
     * The single server-side resolver for every write path (edit, replace,
     * delete): id, filterable_type and the acting user's ownership must all
     * match in one query, so a client-supplied view_key/filterable_type can
     * never resolve a row it doesn't own — public views included, since
     * "public" only grants read, never write. The owner is always
     * Auth::id() itself, never a caller-supplied argument (#138 PR4 ola4A).
     */
    public static function resolveOwnedTableViewOrFail(int $viewId, string $filterableType): self
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new AuthorizationException('An authenticated user is required.');
        }

        $view = static::query()
            ->whereKey($viewId)
            ->where('filterable_type', $filterableType)
            ->where('user_id', $userId)
            ->first();

        if ($view === null) {
            throw new NotFoundHttpException;
        }

        return $view;
    }

    /**
     * Read-only visibility check (own OR public) used to authorize
     * favoriting a saved view — a private view belonging to another user
     * must never become favoritable, even though favorites are keyed to the
     * actor's own user_id (#138 PR4 ola4A).
     */
    public static function assertVisibleOrFail(int $viewId, string $filterableType): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new AuthorizationException('An authenticated user is required.');
        }

        $visible = static::query()
            ->whereKey($viewId)
            ->where('filterable_type', $filterableType)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_public', true);
            })
            ->exists();

        if (! $visible) {
            throw new NotFoundHttpException;
        }
    }
}
