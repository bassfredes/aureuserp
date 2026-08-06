<?php

namespace Webkul\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webkul\Chatter\Concerns\ResolvesChatterCompany;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id is derived from `followable` the same way as Message/Attachment
 * (see ResolvesChatterCompany) — never chosen independently of the record
 * being followed, since a follower's company can only ever be the company
 * of what it follows (#138 PR4 chatter gap, 2026-08-03 Codex adversarial
 * review). Deliberately NOT added to chatter_followers_unique
 * (followable_type/followable_id/partner_id): company_id is a pure
 * function of followable_id, so a given (followable, partner) pair can
 * never legitimately resolve to two different companies — widening the
 * unique key would only allow a data-integrity bug (a duplicate follower
 * row) to hide behind a mismatched company_id instead of being caught by
 * the existing unique constraint. See Message::class docblock for the
 * IncludesSharedCompanyRows rationale, identical here.
 */
class Follower extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, ResolvesChatterCompany;

    protected $table = 'chatter_followers';

    protected $fillable = [
        'company_id',
        'followable_id',
        'followable_type',
        'partner_id',
    ];

    protected $casts = [
        'followed_at' => 'datetime',
    ];

    public function followable(): MorphTo
    {
        return $this->morphTo();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $follower) {
            static::applyChatterOwnerCompany($follower, 'followable');
        });
    }
}
