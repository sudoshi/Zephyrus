<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Roles allowed to read the deployment console (IDN geography, capability matrix,
     * transfer graph, readiness scorecard). Maps the plan's superuser/ops-leader RBAC
     * onto the app's actual users.role values; frontline ('user') and 'executive' are
     * excluded.
     *
     * @var list<string>
     */
    private const DEPLOYMENT_CONSOLE_ROLES = ['super-admin', 'admin', 'superuser', 'ops-leader'];

    /**
     * Roles allowed to author/write deployment + staffing config (the Staffing
     * Alignment Wizard, connector secrets, rule promotion, commit). Strictly narrower
     * than read access — no plain 'admin'/'super-admin' catch-all beyond superuser.
     * Plan §11: role IN ('superuser','ops-leader').
     *
     * @var list<string>
     */
    private const DEPLOYMENT_CONFIG_ROLES = ['super-admin', 'superuser', 'ops-leader'];

    /**
     * Integration control-plane access is intentionally independent from the
     * broader enterprise-setup and staffing administration roles. The scalar
     * users.role value remains authoritative; a Spatie admin assignment must
     * not silently grant access to connector configuration or operational logs.
     *
     * @var list<string>
     */
    private const INTEGRATION_CONTROL_ROLES = ['super_admin', 'superuser'];

    /**
     * Roles allowed to create governed Eddy action proposals or mint the scoped
     * draft token used by the agent callback. Plain authenticated accounts can
     * chat/read, but only operational personas may enter the approvals workflow.
     *
     * @var list<string>
     */
    private const EDDY_ACTION_ROLES = [
        'super-admin',
        'super_admin',
        'superuser',
        'ops-leader',
        'ops_leader',
        'admin',
        'bed_manager',
        'house_supervisor',
        'capacity_lead',
        'charge_nurse',
        'hospitalist',
        'intensivist',
        'transport',
        'evs',
        'staffing_coordinator',
        'pi_lead',
        'periop_manager',
    ];

    /**
     * Roles allowed to create/update legacy OR case API records. Reads stay
     * public for the existing dashboard widgets; writes are constrained to OR,
     * periop, and operational admin roles.
     *
     * @var list<string>
     */
    private const OR_CASE_WRITE_ROLES = [
        'super_admin',
        'superuser',
        'ops_leader',
        'admin',
        'periop_manager',
        'or_nurse',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('viewDeploymentConsole', fn (User $user): bool => in_array(
            (string) $user->role, self::DEPLOYMENT_CONSOLE_ROLES, true
        ));

        Gate::define('manageDeploymentConfig', fn (User $user): bool => in_array(
            (string) $user->role, self::DEPLOYMENT_CONFIG_ROLES, true
        ));

        Gate::define('viewIntegrations', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::INTEGRATION_CONTROL_ROLES,
            true,
        ));

        Gate::define('manageIntegrations', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::INTEGRATION_CONTROL_ROLES,
            true,
        ));

        Gate::define('useEddyActions', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::EDDY_ACTION_ROLES,
            true,
        ) || $user->hasRole(['admin', 'super-admin', 'super_admin']));

        Gate::define('writeOrCases', fn (User $user): bool => in_array(
            self::canonicalRole((string) $user->role),
            self::OR_CASE_WRITE_ROLES,
            true,
        ) || $user->hasRole(['admin', 'super-admin', 'super_admin', 'periop_manager', 'or_nurse']));
    }

    private static function canonicalRole(string $role): string
    {
        return str_replace([' ', '-'], '_', strtolower($role));
    }
}
