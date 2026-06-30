<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema public: roles.
     */
    protected $connection = 'pgsql_public';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Slug del ruolo (es: admin, operatore)');
            $table->string('display_name')->comment('Nome visualizzato del ruolo');
            $table->text('description')->nullable()->comment('Descrizione del ruolo');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
