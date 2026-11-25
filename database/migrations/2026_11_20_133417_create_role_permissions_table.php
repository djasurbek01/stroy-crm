<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Права ролей, настроенные владельцем компании. Хранятся только отличия от стандартных прав:
| allowed = true — право выдано сверх стандарта, false — отобрано.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('role', 20);
            $table->string('cap', 40);
            $table->boolean('allowed');
            $table->unique(['organization_id', 'role', 'cap'], 'uq_role_permission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
