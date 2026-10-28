<?php

declare(strict_types=1);

namespace Helfersystem\Startseite\Migrations;

use Engelsystem\Database\Migration\Migration;

/**
 * Privilege to mail everybody their shift plan, for the groups that can manage all shift entries
 */
class StartseiteSendShiftsPrivilege extends Migration
{
    public function up(): void
    {
        $db = $this->schema->getConnection();
        if ($db->table('privileges')->where('name', 'startseite.send_shifts')->exists()) {
            return;
        }

        $privilegeId = $db->table('privileges')->insertGetId([
            'name' => 'startseite.send_shifts',
            'description' => 'Mail the shift plan as PDF to everybody with shift entries',
        ]);

        $groups = $db->table('group_privileges')
            ->join('privileges', 'privileges.id', 'group_privileges.privilege_id')
            ->where('privileges.name', 'user_shifts_admin')
            ->pluck('group_privileges.group_id');

        $db->table('group_privileges')->insertOrIgnore($groups->map(fn($groupId) => [
            'group_id' => $groupId,
            'privilege_id' => $privilegeId,
        ])->all());
    }

    public function down(): void
    {
        $this->schema->getConnection()->table('privileges')->where('name', 'startseite.send_shifts')->delete();
    }
}
