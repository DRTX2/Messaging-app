<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Per-user "clear chat": hides history for that participant only
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('cleared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $t) => $t->dropColumn('cleared_at'));
        Schema::table('attachments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
        });
    }
};
