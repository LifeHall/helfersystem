<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Executed imports, the shifts of an import share its transaction id and can be removed together
 */
class SchichtimportImports extends Migration
{
    public function up(): void
    {
        $this->schema->create('schichtimport_imports', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('transaction_id')->unique();
            $table->string('filename');
            $table->unsignedInteger('shifts');
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema->drop('schichtimport_imports');
    }
}
