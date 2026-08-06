<?php

namespace Webkul\Chatter\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Chatter\Mail\MessageMail;
use Webkul\Chatter\Models\Follower;
use Webkul\Chatter\Models\Message;
use Webkul\Chatter\Notifications\ChatterDatabaseNotification;
use Webkul\Chatter\Support\ChatterMentions;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

class ChatterNotificationService
{
    protected string $mailView = 'chatter::mail.message-mail';

    public function notifyFollowers(Message $message): void
    {
        $this->viaDatabase($message);

        $this->viaEmail($message);
    }

    protected function viaEmail(Message $message): void
    {
        $record = $message->messageable;

        if (! $record || ! method_exists($record, 'followers')) {
            return;
        }

        // withoutGlobalScope: this is an authoritative system read, not a
        // user session read. Once $record has resolved (the early return
        // above already covers the case where it can't), the ambient
        // CompanyScope reflects the executing process's own session/
        // CompanyContext, not the followers' actual company — a queued
        // listener or console process notifying on behalf of a record it
        // has already legitimately resolved must still see all of that
        // record's real followers regardless of its own scope. Authorization
        // is not weakened by the bypass: followerBelongsToMessageCompany()
        // below is this method's own explicit, narrower check (follower
        // company_id must match the message's owner-derived company_id)
        // and does not depend on CompanyScope at all — same "read broadly,
        // authorize narrowly" pattern as ValidatesRelatedCompanyScope.
        $followers = $record->followers()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('partner')
            ->get()
            ->filter(fn (Follower $follower) => $this->followerBelongsToMessageCompany($follower, $message));

        if ($followers->isEmpty()) {
            return;
        }

        $causer = $message->causer;
        $authorPartnerId = $this->resolveAuthorPartnerId($causer);

        $from = $this->resolveFrom($message, $causer, $record);
        $recordName = $this->resolveRecordName($record);
        $recordUrl = $this->resolveRecordUrl($record);

        foreach ($followers as $follower) {
            $partner = $follower->partner;

            if (! $partner?->email) {
                continue;
            }

            if ($authorPartnerId && (int) $partner->id === (int) $authorPartnerId) {
                continue;
            }

            $payload = [
                'record_url'  => $recordUrl,
                'record_name' => $recordName,
                'model_name'  => class_basename($record),
                'subject'     => __('chatter::filament/resources/actions/chatter/message-action.setup.actions.mail.subject', [
                    'record_name' => $recordName,
                ]),
                'content'     => $this->buildContent($message),
                'from'        => $from,
                'to'          => [
                    'address' => $partner->email,
                    'name'    => $partner->name,
                ],
            ];

            try {
                Mail::to($partner->email, '"'.addslashes((string) $partner->name).'"')
                    ->send(new MessageMail($this->mailView, $payload));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function viaDatabase(Message $message): void
    {
        $record = $message->messageable;

        if (! $record || ! method_exists($record, 'followers')) {
            return;
        }

        $causerUserId = $this->resolveCauserUserId($message->causer);
        $recordName = $this->resolveRecordName($record);
        $recordUrl = $this->resolveRecordUrl($record);
        $causerName = $message->causer?->name ?? 'Someone';

        $mentionedUserIds = $this->notifyMentions($message, $record, $causerUserId, $causerName, $recordName, $recordUrl);

        if ($message->type === 'activity') {
            $this->notifyActivityAssignee($message, $causerUserId, $mentionedUserIds, $causerName, $recordName, $recordUrl);

            return;
        }

        [$titleKey, $icon, $color] = $this->resolveTypeMeta($message);

        $assignedUserId = $this->resolveAssignedUserId($message, $record);

        if (! $assignedUserId && $message->assigned_to) {
            $assignedUserId = (int) $message->assigned_to;
        }

        if ($assignedUserId && $assignedUserId !== $causerUserId && ! in_array($assignedUserId, $mentionedUserIds, true)) {
            $this->sendAssignedNotification($assignedUserId, $causerName, $recordName, $recordUrl);
        }

        $excludedIds = array_merge([$causerUserId, $assignedUserId], $mentionedUserIds);

        $recipients = $this->resolveFollowerUsers($record, $message, $excludedIds);

        if ($recipients->isEmpty()) {
            return;
        }

        $title = __($titleKey, ['causer' => $causerName, 'record' => $recordName]);

        $body = $message->type === 'notification'
            ? ($this->summarizeChanges($message) ?? $this->plainBody($message))
            : $this->plainBody($message);

        foreach ($recipients as $user) {
            $user->notify(new ChatterDatabaseNotification($title, $body, $icon, $color, $recordUrl));
        }
    }

    protected function resolveAssignedUserId(Message $message, Model $record): ?int
    {
        if ($message->type !== 'notification' || ! method_exists($record, 'resolveChatterAssignedUserId')) {
            return null;
        }

        $properties = is_array($message->properties) ? $message->properties : [];

        return $record->resolveChatterAssignedUserId($properties);
    }

    protected function sendAssignedNotification(int $assigneeId, string $causerName, string $recordName, string $recordUrl): void
    {
        $assignee = User::find($assigneeId);

        if (! $assignee) {
            return;
        }

        $assignee->notify(new ChatterDatabaseNotification(
            __('chatter::notifications.database.assigned.title', ['causer' => $causerName, 'record' => $recordName]),
            __('chatter::notifications.database.assigned.body', ['record' => $recordName]),
            'heroicon-o-user-plus',
            'info',
            $recordUrl,
        ));
    }

    protected function summarizeChanges(Message $message): ?string
    {
        $changes = is_array($message->properties) ? $message->properties : [];

        if (empty($changes)) {
            return null;
        }

        $rows = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $field));
            $old = $change['old_value'] ?? null;
            $new = $change['new_value'] ?? null;
            $old = is_array($old) ? implode(', ', $old) : $old;
            $new = is_array($new) ? implode(', ', $new) : $new;

            if (isset($change['old_value']) && isset($change['new_value'])) {
                $rows[] = $label.': '.$old.' → '.$new;
            } elseif (isset($change['new_value'])) {
                $rows[] = $label.': '.$new;
            }
        }

        if (empty($rows)) {
            return null;
        }

        return Str::limit(implode(' · ', $rows), 160);
    }

    protected function notifyMentions(Message $message, Model $record, ?int $causerUserId, string $causerName, string $recordName, string $recordUrl): array
    {
        $mentionedIds = ChatterMentions::extractUserIds($message->body);

        if (empty($mentionedIds)) {
            return [];
        }

        $users = User::with('partner')
            ->whereIn('id', $mentionedIds)
            ->when($causerUserId, fn ($query) => $query->where('id', '!=', $causerUserId))
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $title = __('chatter::notifications.database.mention.title', ['causer' => $causerName, 'record' => $recordName]);
        $body = $this->plainBody($message);

        foreach ($users as $user) {
            if ($user->partner) {
                $record->addFollower($user->partner);
            }

            $user->notify(new ChatterDatabaseNotification($title, $body, 'heroicon-o-at-symbol', 'warning', $recordUrl));
        }

        return $users->pluck('id')->all();
    }

    protected function notifyActivityAssignee(Message $message, ?int $causerUserId, array $mentionedUserIds, string $causerName, string $recordName, string $recordUrl): void
    {
        $assigneeId = $message->assigned_to;

        if (! $assigneeId || (int) $assigneeId === (int) $causerUserId || in_array((int) $assigneeId, $mentionedUserIds, true)) {
            return;
        }

        $assignee = User::find($assigneeId);

        if (! $assignee) {
            return;
        }

        $assignee->notify(new ChatterDatabaseNotification(
            __('chatter::notifications.database.activity.title', ['causer' => $causerName, 'record' => $recordName]),
            $this->plainBody($message),
            'heroicon-o-clock',
            'info',
            $recordUrl,
        ));
    }

    protected function resolveFollowerUsers(Model $record, Message $message, array $excludedIds)
    {
        $excludedIds = array_filter($excludedIds);

        // withoutGlobalScope: same rationale as viaEmail() above.
        return $record->followers()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('partner.user')
            ->get()
            ->filter(fn (Follower $follower) => $this->followerBelongsToMessageCompany($follower, $message))
            ->map(fn ($follower) => $follower->partner?->user)
            ->filter()
            ->reject(fn (User $user) => in_array((int) $user->id, array_map('intval', $excludedIds), true))
            ->unique('id')
            ->values();
    }

    /**
     * Closes the cross-company notification leak Codex flagged in the #138
     * PR4 adversarial review (2026-08-03): this used to load every
     * follower of $record and email/notify all of them regardless of which
     * company the triggering Message actually belongs to — a follower of
     * company A could receive email/database notifications for a message
     * created in company B. Strict equality is intentional and sufficient:
     * both Follower.company_id and Message.company_id are independently
     * derived from the very same `$record` (see ResolvesChatterCompany), so
     * a legitimately shared/company_or_shared record naturally produces
     * null on both sides — no special-casing needed to keep those visible,
     * and any other null/non-null asymmetry is exactly the data-drift
     * scenario (e.g. a not-yet-backfilled legacy row) this must reject.
     */
    protected function followerBelongsToMessageCompany(Follower $follower, Message $message): bool
    {
        $followerCompanyId = $follower->company_id !== null ? (int) $follower->company_id : null;
        $messageCompanyId = $message->company_id !== null ? (int) $message->company_id : null;

        return $followerCompanyId === $messageCompanyId;
    }

    protected function resolveTypeMeta(Message $message): array
    {
        if ($message->type === 'notification') {
            return match ($message->event) {
                'created' => ['chatter::notifications.database.created.title', 'heroicon-o-plus-circle', 'success'],
                default   => ['chatter::notifications.database.updated.title', 'heroicon-o-pencil-square', 'primary'],
            };
        }

        return ['chatter::notifications.database.message.title', 'heroicon-o-chat-bubble-left-ellipsis', 'primary'];
    }

    protected function resolveCauserUserId(mixed $causer): ?int
    {
        if (! $causer) {
            return null;
        }

        if ($causer instanceof User) {
            return (int) $causer->id;
        }

        return isset($causer->user_id) ? (int) $causer->user_id : null;
    }

    protected function plainBody(Message $message): string
    {
        $content = strip_tags($message->body ?? $message->summary ?? '');

        return Str::limit(trim($content), 140);
    }

    protected function buildContent(Message $message): string
    {
        $content = $message->body ?? $message->summary ?? '';

        $changes = is_array($message->properties) ? $message->properties : [];

        if ($message->event === 'created' || empty($changes)) {
            return $content;
        }

        $rows = [];

        foreach ($changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $field));
            $old = $change['old_value'] ?? null;
            $new = $change['new_value'] ?? null;
            $old = is_array($old) ? implode(', ', $old) : $old;
            $new = is_array($new) ? implode(', ', $new) : $new;

            if (isset($change['old_value']) && isset($change['new_value'])) {
                $rows[] = e($label).': '.e((string) $old).' → '.e((string) $new);
            } elseif (isset($change['new_value'])) {
                $rows[] = e($label).': '.e((string) $new);
            }
        }

        if (empty($rows)) {
            return $content;
        }

        return $content.'<ul><li>'.implode('</li><li>', $rows).'</li></ul>';
    }

    protected function resolveAuthorPartnerId(mixed $causer): ?int
    {
        if (! $causer) {
            return null;
        }

        if ($causer instanceof Partner) {
            return (int) $causer->id;
        }

        return $causer->partner_id ? (int) $causer->partner_id : null;
    }

    protected function resolveFrom(Message $message, mixed $causer, mixed $record): array
    {
        $from = [
            'address' => $causer?->email ?? config('mail.from.address'),
            'name'    => $causer?->name ?? config('mail.from.name'),
        ];

        $company = $message->company ?? ($record->company ?? null);

        if ($company) {
            $from['company'] = $company->toArray();
        }

        return $from;
    }

    protected function resolveRecordName(mixed $record): string
    {
        $attribute = property_exists($record, 'recordTitleAttribute') ? $record->recordTitleAttribute : null;

        return (string) ($record->name
            ?? ($attribute ? $record->getAttribute($attribute) : null)
            ?? $record->title
            ?? $record->getKey());
    }

    protected function resolveRecordUrl(mixed $record): string
    {
        if (method_exists($record, 'getChatterResourceUrl')) {
            return (string) $record->getChatterResourceUrl();
        }

        return '';
    }
}
