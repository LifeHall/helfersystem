<?php

declare(strict_types=1);

namespace Helfersystem\Startseite\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Order of the pinned news, saved choices of the guided sign up and its privilege
 */
class StartseiteTables extends Migration
{
    public function up(): void
    {
        if (!$this->schema->hasTable('startseite_news_order')) {
            $this->schema->create('startseite_news_order', function (Blueprint $table): void {
                $table->unsignedInteger('news_id')->primary();
                $table->unsignedInteger('position');
                $table->foreign('news_id')->references('id')->on('news')->cascadeOnDelete();
            });
        }

        if (!$this->schema->hasTable('startseite_signup_choices')) {
            $this->schema->create('startseite_signup_choices', function (Blueprint $table): void {
                $table->unsignedInteger('user_id')->primary();
                $table->json('angel_types');
                $table->json('days');
                $table->timestamps();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        $db = $this->schema->getConnection();
        if ($db->table('privileges')->where('name', 'startseite.signup')->exists()) {
            return;
        }

        $privilegeId = $db->table('privileges')->insertGetId([
            'name' => 'startseite.signup',
            'description' => 'Guided sign up for shifts on the start page',
        ]);

        // Everybody who can sign up in the day plan or the shift list
        $groups = $db->table('group_privileges')
            ->join('privileges', 'privileges.id', 'group_privileges.privilege_id')
            ->whereIn('privileges.name', ['tagesplan.view', 'user_shifts'])
            ->distinct()
            ->pluck('group_privileges.group_id');

        $db->table('group_privileges')->insertOrIgnore($groups->map(fn($groupId) => [
            'group_id' => $groupId,
            'privilege_id' => $privilegeId,
        ])->all());
    }

    public function down(): void
    {
        $this->schema->dropIfExists('startseite_signup_choices');
        $this->schema->dropIfExists('startseite_news_order');
        $this->schema->getConnection()->table('privileges')->where('name', 'startseite.signup')->delete();
    }
}
