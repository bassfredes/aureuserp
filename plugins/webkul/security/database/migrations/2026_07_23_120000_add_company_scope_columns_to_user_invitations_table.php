<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #138 PR4 ola4B: user_invitations previously carried only id/email/
// timestamps — InvitationFactory/InvitationResource already referenced
// role_id/token/expires_at/invited_by/accepted_at as if they existed
// (dead/drifted code, never wired to a real column). This migration adds
// those plus company_id, the column the approved contract requires:
// company_id is captured from the inviting actor's own authorized company
// at issue time and carried through to the accepted User.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('email')->constrained('companies')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('company_id')->constrained('roles')->nullOnDelete();
            $table->string('token')->nullable()->after('role_id');
            $table->foreignId('invited_by')->nullable()->after('token')->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->after('invited_by');
            $table->timestamp('accepted_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('token');
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('expires_at');
            $table->dropColumn('accepted_at');
        });
    }
};
