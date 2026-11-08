<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::create('user_preferences',function(Blueprint $t){$t->id();$t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();$t->string('language',5)->default('fr');$t->string('theme',12)->default('light');$t->jsonb('notifications')->nullable();$t->timestamps();});}public function down():void{Schema::dropIfExists('user_preferences');}};
