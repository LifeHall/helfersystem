<?php

declare(strict_types=1);

namespace Helfersystem\Tagesplan\Migrations;

use Engelsystem\Database\Migration\Migration;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;

/**
 * Own privilege for the day plan, so the shifts page can be hidden independently
 */
class TagesplanViewPrivilege extends Migration
{
    protected Connection $db;

    public function __construct(SchemaBuilder $schema)
    {
        parent::__construct($schema);
        $this->db = $this->schema->getConnection();
    }

    /**
     * Creates the privilege and grants it to all groups that can see the shifts
     */
    public function up(): void
    {
        if ($this->db->table('privileges')->where('name', 'tagesplan.view')->exists()) {
            return;
        }

        $privilegeId = $this->db->table('privileges')
            ->insertGetId([
                'name' => 'tagesplan.view',
                'description' => 'View the day plan and sign up for shifts there',
            ]);

        $groups = $this->db->table('group_privileges')
            ->join('privileges', 'privileges.id', 'group_privileges.privilege_id')
            ->where('privileges.name', 'user_shifts')
            ->pluck('group_privileges.group_id');

        $this->db->table('group_privileges')
            ->insertOrIgnore($groups->map(fn($groupId) => [
                'group_id' => $groupId,
                'privilege_id' => $privilegeId,
            ])->all());
    }

    public function down(): void
    {
        $this->db->table('privileges')
            ->where('name', 'tagesplan.view')
            ->delete();
    }
}
