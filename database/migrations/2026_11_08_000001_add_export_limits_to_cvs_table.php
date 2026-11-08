<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cvs', function (Blueprint $table) {
            $table->unsignedTinyInteger('print_count')->default(0);
            $table->unsignedTinyInteger('modification_count')->default(0);
            $table->string('modification_session_id', 64)->nullable();
            $table->timestamp('modification_session_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cvs', function (Blueprint $table) {
            $table->dropColumn([
                'print_count',
                'modification_count',
                'modification_session_id',
                'modification_session_expires_at',
            ]);
        });
    }
};
