<?php

namespace App\Providers;

use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Repositories\ClientRepositoryInterface;
use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Contracts\Repositories\CountryRepositoryInterface;
use App\Contracts\Repositories\DocumentTypeRepositoryInterface;
use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Contracts\Repositories\HealthActivityRepositoryInterface;
use App\Contracts\Repositories\HealthPlanCategoryRepositoryInterface;
use App\Contracts\Repositories\HealthPlanTemplateRepositoryInterface;
use App\Contracts\Repositories\TechniqueRepositoryInterface;
use App\Contracts\Repositories\TutorialRepositoryInterface;
use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\SupportMessageRepositoryInterface;
use App\Contracts\Repositories\SupportMessageReplyRepositoryInterface;
use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Repositories\VetRepositoryInterface;
use App\Models\Client;
use App\Models\Export;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Policies\ExportPolicy;
use App\Repositories\ClientRepositoryEloquent;
use App\Repositories\ContactRepositoryEloquent;
use App\Repositories\CountryRepositoryEloquent;
use App\Repositories\DocumentTypeRepositoryEloquent;
use App\Repositories\EstablishmentRepositoryEloquent;
use App\Repositories\HealthActivityRepositoryEloquent;
use App\Repositories\HealthPlanCategoryRepositoryEloquent;
use App\Repositories\HealthPlanTemplateRepositoryEloquent;
use App\Repositories\TechniqueRepositoryEloquent;
use App\Repositories\TutorialRepositoryEloquent;
use App\Repositories\ExportRepositoryEloquent;
use App\Repositories\NotificationRepositoryEloquent;
use App\Repositories\PermissionRepositoryEloquent;
use App\Repositories\RoleRepositoryEloquent;
use App\Repositories\SupportMessageRepositoryEloquent;
use App\Repositories\SupportMessageReplyRepositoryEloquent;
use App\Repositories\SystemSettingRepositoryEloquent;
use App\Repositories\UserProfileRepositoryEloquent;
use App\Repositories\UserRepositoryEloquent;
use App\Repositories\VetRepositoryEloquent;
use App\Services\Exports\ExportResolverService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepositoryEloquent::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepositoryEloquent::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepositoryEloquent::class);
        $this->app->bind(ExportRepositoryInterface::class, ExportRepositoryEloquent::class);
        $this->app->bind(SupportMessageRepositoryInterface::class, SupportMessageRepositoryEloquent::class);
        $this->app->bind(SupportMessageReplyRepositoryInterface::class, SupportMessageReplyRepositoryEloquent::class);
        $this->app->bind(ExportResolverInterface::class, ExportResolverService::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepositoryEloquent::class);
        $this->app->bind(SystemSettingRepositoryInterface::class, SystemSettingRepositoryEloquent::class);
        $this->app->bind(CountryRepositoryInterface::class, CountryRepositoryEloquent::class);
        $this->app->bind(DocumentTypeRepositoryInterface::class, DocumentTypeRepositoryEloquent::class);
        $this->app->bind(VetRepositoryInterface::class, VetRepositoryEloquent::class);
        $this->app->bind(UserProfileRepositoryInterface::class, UserProfileRepositoryEloquent::class);
        $this->app->bind(ContactRepositoryInterface::class, ContactRepositoryEloquent::class);
        $this->app->bind(ClientRepositoryInterface::class, ClientRepositoryEloquent::class);
        $this->app->bind(EstablishmentRepositoryInterface::class, EstablishmentRepositoryEloquent::class);
        $this->app->bind(TutorialRepositoryInterface::class, TutorialRepositoryEloquent::class);
        $this->app->bind(TechniqueRepositoryInterface::class, TechniqueRepositoryEloquent::class);
        $this->app->bind(HealthActivityRepositoryInterface::class, HealthActivityRepositoryEloquent::class);
        $this->app->bind(HealthPlanCategoryRepositoryInterface::class, HealthPlanCategoryRepositoryEloquent::class);
        $this->app->bind(HealthPlanTemplateRepositoryInterface::class, HealthPlanTemplateRepositoryEloquent::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email', '').$request->ip());
        });

        Gate::policy(Export::class, ExportPolicy::class);

        // Tenant gate: si la request tiene un current_profile (cargado por vet.tenant u otro
        // middleware de tenant), verificar el permiso contra el rol del perfil antes de que
        // Spatie resuelva los roles de plataforma del User.
        Gate::before(function (User $user, string $ability): ?bool {
            /** @var UserProfile|null $profile */
            $profile = request()->attributes->get('current_profile');

            if (!$profile) {
                return null;
            }

            $role = $profile->role;

            if ($role?->hasPermissionTo($ability)) {
                return true;
            }

            return null;
        });

        Relation::morphMap([
            'vet'          => Vet::class,
            'user_profile' => UserProfile::class,
            'client'       => Client::class,
        ]);
    }
}
