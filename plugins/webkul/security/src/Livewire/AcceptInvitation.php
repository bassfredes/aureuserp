<?php

namespace Webkul\Security\Livewire;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Webkul\Project\Filament\Pages\Dashboard;
use Webkul\Security\Models\Invitation;
use Webkul\Security\Models\User;
use Webkul\Security\Settings\UserSettings;

class AcceptInvitation extends SimplePage
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected string $view = 'security::livewire.accept-invitation';

    // Locked so a tampered Livewire request payload cannot retarget the
    // component at a different invitation between the signed GET and a
    // later POST action — but Locked alone is client-side defense in
    // depth, not the authorization. $token below is that authorization
    // (#138 PR4 IDOR fix, Codex adversarial review).
    #[Locked]
    public int $invitation;

    // The signed URL's `token` query parameter (Invitation::$token,
    // random per row — see UserInvitationMail::content()). This, not the
    // route's `signed` middleware, is what create() re-validates inside
    // the transaction: `signed` only guards the initial GET, never the
    // Livewire `/livewire/update` POST that create() runs on, so without
    // this check a request with a swapped $invitation id would complete
    // the mutation against a different invitation than the one the
    // signature was ever issued for.
    #[Locked]
    public ?string $token = null;

    private Invitation $invitationModel;

    public ?array $data = [];

    public function mount(?string $token = null): void
    {
        $this->token = $token ?? request()->query('token');

        $this->invitationModel = Invitation::findOrFail($this->invitation);

        $this->assertTokenMatches($this->invitationModel);

        // The signed URL's own signature is this route's authorization —
        // it proves the link came from the mail this invitation actually
        // sent. Expiry/already-accepted state must still be enforced
        // explicitly, since a valid signature says nothing about whether
        // the invitation is still usable (#138 PR4 ola4B).
        abort_if($this->invitationModel->isAccepted(), 410, __('security::livewire/accept-invitation.errors.already-accepted'));
        abort_if($this->invitationModel->isExpired(), 410, __('security::livewire/accept-invitation.errors.expired'));

        $this->form->fill([
            'email' => $this->invitationModel->email,
        ]);
    }

    /**
     * Fails closed unless $this->token is a non-empty, constant-time
     * match against the given invitation's stored token. This is the
     * actual authorization anchor for both mount() (initial GET, already
     * covered by the `signed` middleware, checked again here for
     * consistency) and create() (the mutating POST, which the `signed`
     * middleware never touches).
     */
    private function assertTokenMatches(Invitation $invitation): void
    {
        abort_unless(
            is_string($this->token)
                && $this->token !== ''
                && is_string($invitation->token)
                && $invitation->token !== ''
                && hash_equals($invitation->token, $this->token),
            403,
            __('security::livewire/accept-invitation.errors.invalid-token'),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('security::livewire/accept-invitation.form.name.label'))
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                TextInput::make('email')
                    ->label(__('security::livewire/accept-invitation.form.email.label'))
                    ->disabled(),
                TextInput::make('password')
                    ->label(__('security::livewire/accept-invitation.form.password.label'))
                    ->password()
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation')
                    ->validationAttribute(__('security::livewire/accept-invitation.form.password.validation_attribute')),
                TextInput::make('passwordConfirmation')
                    ->label(__('security::livewire/accept-invitation.form.password_confirmation.label'))
                    ->password()
                    ->required()
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $formState = $this->form->getState();

        DB::transaction(function () use ($formState): void {
            // lockForUpdate() closes the race between two requests racing
            // the same still-valid signed URL — without it, both could
            // pass the not-accepted/not-expired checks below and both
            // create a User from the same Invitation (#138 PR4 ola4B).
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($this->invitation);

            // Re-anchor authorization here, inside the mutation's own
            // transaction, against the row actually locked for update —
            // not just the unlocked copy checked in mount(). This is what
            // stops a tampered $invitation id from ever completing an
            // account creation for an invitation the caller's signed URL
            // was never issued for (#138 PR4 IDOR fix).
            $this->assertTokenMatches($invitation);

            abort_if($invitation->isAccepted(), 410, __('security::livewire/accept-invitation.errors.already-accepted'));
            abort_if($invitation->isExpired(), 410, __('security::livewire/accept-invitation.errors.expired'));

            // The company/role captured at issue time (Invitation::boot(),
            // ListUsers::inviteUser action) are what the accepted User
            // inherits — never a global UserSettings default, which would
            // let any invitee land in whatever company happens to be
            // configured at accept time rather than the one the inviter
            // was actually authorized for (#138 PR4 ola4B).
            $user = User::create([
                'name'               => $formState['name'],
                'password'           => $formState['password'],
                'email'              => $invitation->email,
                'default_company_id' => $invitation->company_id ?? settings(UserSettings::class)->default_company_id,
            ]);

            if ($invitation->company_id !== null) {
                $user->allowedCompanies()->syncWithoutDetaching([$invitation->company_id]);
            }

            $user->assignRole($invitation->role_id ?? settings(UserSettings::class)->default_role_id);

            $invitation->update(['accepted_at' => now()]);

            $this->invitationModel = $invitation;
        });

        $this->redirect(Dashboard::getUrl());
    }

    /**
     * @return array<Action | ActionGroup>
     */
    public function getFormActions(): array
    {
        return [
            $this->getRegisterFormAction(),
        ];
    }

    public function getRegisterFormAction(): Action
    {
        return Action::make('register')
            ->label(__('security::livewire/accept-invitation.form.actions.register.label'))
            ->submit('register');
    }

    public function getHeading(): string
    {
        return 'Accept Invitation';
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function getSubHeading(): string
    {
        return __('security::livewire/accept-invitation.header.sub-heading.accept-invitation');
    }
}
