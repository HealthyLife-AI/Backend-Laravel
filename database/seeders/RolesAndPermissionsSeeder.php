<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the three MVP roles and the permission matrix from PRD §2.2.
 *
 * This is Layer 1 of the two-layer permission model ("can this ROLE
 * perform this action?"). Layer 2 — "can this user touch THIS row?" —
 * is the per-nutritionist data-isolation scope
 * (App\Models\Scopes\NutritionistScope), which Spatie roles do not
 * replace: a nutritionist holding `plans.manage` must still never read
 * another nutritionist's clients (PRD §2.2 security rule).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * PRD §2.2 permission matrix: permission => roles that hold it.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'clients.manage' => ['nutritionist'],
        'health_profile.manage' => ['nutritionist'],
        'plans.manage' => ['nutritionist'],
        'plans.view.own' => ['client'],
        'logs.manage.own' => ['client'],
        'progress.view' => ['nutritionist', 'client'],
        'alerts.view' => ['nutritionist'],
        'ai_summary.view' => ['nutritionist'],
        'foods.suggest' => ['nutritionist'],
        'foods.manage' => ['admin'],
        'foods.approve' => ['admin'],
        'users.manage' => ['admin'],
    ];

    public function run(): void
    {
        // Guard is 'web' — the only guard configured (config/auth.php); the
        // app authenticates via the custom `jwt` middleware rather than a
        // named Laravel guard, so this must match the guard Spatie resolves
        // by default for the User model or role checks silently fail.
        $roles = collect(['nutritionist', 'client', 'admin'])
            ->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);

        foreach (self::MATRIX as $permissionName => $roleNames) {
            $permission = Permission::findOrCreate($permissionName, 'web');

            foreach ($roleNames as $roleName) {
                $roles[$roleName]->givePermissionTo($permission);
            }
        }
    }
}
