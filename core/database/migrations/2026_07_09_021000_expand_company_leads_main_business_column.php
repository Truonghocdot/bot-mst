<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_leads', function (Blueprint $table) {
            $table->text('main_business')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_leads', function (Blueprint $table) {
            $table->string('main_business')->nullable()->change();
        });
    }
};
