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
use Webkul\Project\Filament\Pages\Dashboard;
use Webkul\Security\Models\Invitation;
use Webkul\Security\Models\User;
use Webkul\Security\Settings\UserSettings;

class AcceptInvitation extends SimplePage
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected string $view = 'security::livewire.accept-invitation';

    public int $invitation;

    private Invitation $invitationModel;

    public ?array $data = [];

    public function mount(): void
    {
        $this->invitationModel = Invitation::findOrFail($this->invitation);

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
