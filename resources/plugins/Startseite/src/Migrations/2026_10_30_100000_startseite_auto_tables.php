<?php

declare(strict_types=1);

namespace Helfersystem\Startseite\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Automatic assignment: wishes of the helpers and the shift entries the system made (only these can be moved)
 */
class StartseiteAutoTables extends Migration
{
    public function up(): void
    {
        if (!$this->schema->hasTable('startseite_auto_wishes')) {
            $this->schema->create('startseite_auto_wishes', function (Blueprint $table): void {
                $table->unsignedInteger('user_id')->primary();
                $table->json('angel_types');
                $table->json('windows');
                $table->unsignedInteger('count');
                $table->timestamps();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!$this->schema->hasTable('startseite_auto_entries')) {
            $this->schema->create('startseite_auto_entries', function (Blueprint $table): void {
                $table->unsignedInteger('shift_entry_id')->primary();
                $table->unsignedInteger('user_id')->index();
                $table->foreign('shift_entry_id')->references('id')->on('shift_entries')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $this->schema->dropIfExists('startseite_auto_entries');
        $this->schema->dropIfExists('startseite_auto_wishes');
    }
}
