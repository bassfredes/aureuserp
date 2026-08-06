<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Webkul\Chatter\Mail\MessageMail;
use Webkul\Chatter\Models\Follower;
use Webkul\Chatter\Notifications\ChatterDatabaseNotification;
use Webkul\Chatter\Services\ChatterNotificationService;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function notificationEmployeeIn(int $companyId): Employee
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Employee::factory()->create(['company_id' => $companyId]),
    );
}

function notificationCompany(): Company
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Company::factory()->create(),
    );
}

function notificationPartnerWithUser(array $partnerAttributes = []): Partner
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: function () use ($partnerAttributes) {
            $user = User::withoutEvents(fn () => User::factory()->create());
            $partner = Partner::factory()->create(array_merge(['user_id' => $user->id], $partnerAttributes));

            return $partner;
        },
    );
}

/**
 * #138 PR4 chatter gap, 2026-08-03 Codex adversarial review: before this
 * fix, ChatterNotificationService::viaEmail()/resolveFollowerUsers() loaded
 * every follower of the triggering record and emailed/notified all of them
 * regardless of company — a follower of company A could receive
 * email/database notifications for a message created in company B. These
 * tests exercise the fix directly against notifyFollowers(), bypassing the
 * app()->terminating() deferral Message::boot() normally wraps it in (an
 * unrelated pre-existing wiring detail, not what this fix is about).
 */
it('emails a follower whose company matches the message company, and skips a follower whose company does not', function () {
    Mail::fake();

    $companyA = notificationCompany();
    $companyB = notificationCompany();
    $employeeA = notificationEmployeeIn($companyA->id);
    $partnerSameCompany = notificationPartnerWithUser(['email' => 'same-company@example.test']);
    $partnerOtherCompany = notificationPartnerWithUser(['email' => 'other-company@example.test']);

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    $employeeA->addFollower($partnerSameCompany);

    // Simulate a follower row mislabeled with a different company than its
    // own followable owner — the exact leak scenario Codex flagged (a
    // not-yet-backfilled legacy row, or any other drift). Bypasses
    // Follower's own saving hook on purpose: that hook's job (tested in
    // ChatterCompanyScopeTest) is to prevent this on the write path; this
    // test is about the READ-side notification filter catching it anyway
    // as defense in depth.
    Follower::withoutEvents(function () use ($employeeA, $partnerOtherCompany, $companyB) {
        Follower::create([
            'followable_type' => $employeeA->getMorphClass(),
            'followable_id'   => $employeeA->id,
            'partner_id'      => $partnerOtherCompany->id,
            'company_id'      => $companyB->id,
        ]);
    });

    $message = $employeeA->addMessage(['body' => 'hello everyone']);

    app(ChatterNotificationService::class)->notifyFollowers($message);

    Mail::assertSent(MessageMail::class, fn ($mail) => $mail->payload['to']['address'] === 'same-company@example.test');
    Mail::assertNotSent(MessageMail::class, fn ($mail) => $mail->payload['to']['address'] === 'other-company@example.test');
});

it('notifies a follower whose company matches the message company via database notification, and skips a mismatched one', function () {
    Notification::fake();

    $companyA = notificationCompany();
    $companyB = notificationCompany();
    $employeeA = notificationEmployeeIn($companyA->id);
    $partnerSameCompany = notificationPartnerWithUser();
    $partnerOtherCompany = notificationPartnerWithUser();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    $employeeA->addFollower($partnerSameCompany);

    Follower::withoutEvents(function () use ($employeeA, $partnerOtherCompany, $companyB) {
        Follower::create([
            'followable_type' => $employeeA->getMorphClass(),
            'followable_id'   => $employeeA->id,
            'partner_id'      => $partnerOtherCompany->id,
            'company_id'      => $companyB->id,
        ]);
    });

    $message = $employeeA->addMessage(['body' => 'hello everyone']);

    app(ChatterNotificationService::class)->notifyFollowers($message);

    Notification::assertSentTo($partnerSameCompany->user, ChatterDatabaseNotification::class);
    Notification::assertNotSentTo($partnerOtherCompany->user, ChatterDatabaseNotification::class);
});
