<?php

namespace Webkul\Chatter\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Webkul\Chatter\Concerns\ResolvesChatterCompany;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id is derived from `messageable` the same way as Message (see
 * ResolvesChatterCompany), and when message_id is present it must also
 * agree with the referenced Message's own messageable/company_id — an
 * Attachment cannot be re-parented to a Message belonging to a different
 * owner or company (#138 PR4 chatter gap, 2026-08-03 Codex adversarial
 * review). See Message::class docblock for the IncludesSharedCompanyRows
 * rationale, identical here.
 */
class Attachment extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, ResolvesChatterCompany;

    protected $table = 'chatter_attachments';

    protected $fillable = [
        'company_id',
        'creator_id',
        'message_id',
        'file_size',
        'name',
        // 'messageable' (the bare, non-existent column name) was here
        // instead of the two real polymorphic columns below — direct mass
        // assignment of a messageable owner (Attachment::create([...]))
        // always silently dropped messageable_type/messageable_id before
        // this fix; only the attachments() relation's own save() (which
        // sets attributes directly, bypassing $fillable) ever worked.
        'messageable_type',
        'messageable_id',
        'file_path',
        'original_file_name',
        'mime_type',
    ];

    protected $appends = ['url'];

    public function messageable()
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($attachment) {
            $attachment->creator_id ??= Auth::id();
        });

        static::saving(function (self $attachment) {
            $owner = static::applyChatterOwnerCompany($attachment, 'messageable');

            static::assertMessageConsistency($attachment, $owner);
        });

        static::deleted(function ($attachment) {
            $filePath = $attachment->file_path;

            if (
                $filePath
                && Storage::disk('public')->exists($filePath)
            ) {
                Storage::disk('public')->delete($filePath);
            }
        });
    }

    /**
     * message_id is optional (nullable, per $fillable), but when present
     * the referenced Message must belong to the same messageable owner AND
     * the same company as this Attachment — otherwise an attachment could
     * be filed under a Message from a different record or company entirely
     * (#138 PR4 chatter gap review).
     */
    protected static function assertMessageConsistency(self $attachment, Model $owner): void
    {
        if ($attachment->message_id === null) {
            return;
        }

        $message = Message::withoutGlobalScope(CompanyScope::class)->find($attachment->message_id);

        if (! $message) {
            throw new AuthorizationException('The Message referenced by this Attachment\'s message_id could not be found.');
        }

        if (
            $message->messageable_type !== $owner->getMorphClass()
            || (int) $message->messageable_id !== (int) $owner->getKey()
        ) {
            throw new AuthorizationException('The Message referenced by this Attachment does not belong to the same messageable owner.');
        }

        if ($message->company_id !== $attachment->company_id) {
            throw new AuthorizationException('The Message referenced by this Attachment belongs to a different company.');
        }
    }
}
