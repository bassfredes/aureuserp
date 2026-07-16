<?php

use Webkul\Support\Models\EmailTemplate;
use Webkul\Support\Services\EmailTemplateService;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

/**
 * EmailTemplate had real consumers (EmailTemplateService, EmailTemplateResource,
 * a factory) but its own migration file was missing from the repo — the
 * SupportServiceProvider's migration whitelist referenced
 * `2025_01_03_061444_create_email_templates_table` by name, but no such file
 * existed, so the `email_templates` table was never created
 * (Intelligent-Integration-Suite#138 audit, PR 0, "modelo huérfano" case 2
 * of 2 — recreated the migration here rather than deleting the model,
 * since real code still depends on it).
 */
it('creates and queries an EmailTemplate now that its migration exists', function () {
    expect(Illuminate\Support\Facades\Schema::hasTable('email_templates'))->toBeTrue();

    $template = EmailTemplate::create(['code' => 'welcome-email', 'name' => 'Welcome', 'subject' => 'Hi', 'content' => 'Hello!']);

    expect(EmailTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});

it('resolves an active template by code through EmailTemplateService', function () {
    EmailTemplate::create([
        'code'      => 'order-confirmation',
        'name'      => 'Order Confirmation',
        'subject'   => 'Your order',
        'content'   => 'Thanks for your order!',
        'is_active' => true,
    ]);

    $resolved = (new EmailTemplateService)->getTemplate('order-confirmation');

    expect($resolved->code)->toBe('order-confirmation');
});
