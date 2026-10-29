<?php

declare(strict_types=1);

namespace Helfersystem\Familienkonten\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Family members may share a mail address: the email of the users is no longer unique
 */
class FamilienkontenSharedEmail extends Migration
{
    public function up(): void
    {
        $this->schema->table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->index('email');
        });
    }

    /**
     * Fails as long as accounts share a mail address
     */
    public function down(): void
    {
        $this->schema->table('users', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }
}
