<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 既存 user は求職者にする（docs/domain-design.md 1 章）。
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('seeker')->after('email');
        });

        // 既定値は既存行を埋めるためだけに使う。以後は作る側（register・招待の受諾）がロールを明示する。
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
