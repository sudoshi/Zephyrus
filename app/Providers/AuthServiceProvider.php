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
    }
}
