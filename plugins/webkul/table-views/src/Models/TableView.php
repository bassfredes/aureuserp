<?php

namespace Webkul\TableViews\Models;

use Illuminate\Database\Eloquent\Model;
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

    /**
     * The single server-side resolver for every write path (edit, replace,
     * delete): id, filterable_type and the acting user's ownership must all
     * match in one query, so a client-supplied view_key/filterable_type can
     * never resolve a row it doesn't own — public views included, since
     * "public" only grants read, never write (#138, PR 4 ola 4A).
     */
    public static function resolveOwnedTableViewOrFail(int $viewId, string $filterableType, int $userId): self
    {
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
     * actor's own user_id (#138, PR 4 ola 4A).
     */
    public static function assertVisibleOrFail(int $viewId, string $filterableType, int $userId): void
    {
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
