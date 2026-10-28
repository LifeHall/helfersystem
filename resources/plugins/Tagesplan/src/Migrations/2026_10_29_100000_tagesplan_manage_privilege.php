<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan\Migrations;

use Engelsystem\Database\Migration\Migration;

/**
 * Privilege to see the day plan even if it is hidden from the helpers, for the groups that manage all shifts
 */
class TagesplanManagePrivilege extends Migration
{
    public function up(): void
    {
        $db = $this->schema->getConnection();
        if ($db->table('privileges')->where('name', 'tagesplan.manage')->exists()) {
            return;
        }

        $privilegeId = $db->table('privileges')->insertGetId([
            'name' => 'tagesplan.manage',
            'description' => 'Always see the day plan, also if it is hidden from the helpers',
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
        $this->schema->getConnection()->table('privileges')->where('name', 'tagesplan.manage')->delete();
    }
}
