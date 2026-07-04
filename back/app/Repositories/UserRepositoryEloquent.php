<?php

namespace App\Repositories;

use App\Contracts\QueryCriterion;
use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use App\Models\UserPasswordHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserRepositoryEloquent extends BaseRepositoryEloquent implements UserRepositoryInterface
{
    protected function model(): string
    {
        return User::class;
    }

    public function findByGuid(string $guid): ?User
    {
        return $this->newQuery()->where('guid', $guid)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->newQuery()->where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return $this->model->newQuery()->create($data);
    }

    public function list(int $perPage, QueryCriterion ...$criteria): LengthAwarePaginator
    {
        return $this->buildQuery(...$criteria)
            ->with(['roles', 'profiles.role', 'profiles.authenticatable'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function exportQuery(QueryCriterion ...$criteria): Builder
    {
        return $this->buildQuery(...$criteria)->orderBy('created_at', 'desc');
    }

    public function destroy(Model $model): bool|null
    {
        $model->tokens()->delete();

        return $model->delete();
    }

    public function toggleLock(User $user): User
    {
        if ($user->locked_at === null) {
            $user->locked_at = now();
        } else {
            $user->locked_at = null;
            $user->failed_login_attempts = 0;
        }

        $user->save();

        return $user;
    }

    public function updatePassword(User $user, string $password): User
    {
        // 1. Verificar historial ANTES de modificar el usuario
        $this->checkPasswordHistory($user, $password);

        // 2. Hashear el password nuevo
        $hashedPassword = Hash::make($password);

        // 3. Persistir el cambio en el usuario
        $user->password              = $hashedPassword;
        $user->failed_login_attempts = 0;
        $user->locked_at             = null;
        $user->password_changed_at   = now();
        $user->save();

        // 4. Guardar en historial usando el hash ya generado
        $this->storePasswordHistory($user, $hashedPassword);

        return $user;
    }

    private function checkPasswordHistory(User $user, string $plainPassword): void
    {
        /** @var SystemSettingRepositoryInterface $settingRepo */
        $settingRepo = app(SystemSettingRepositoryInterface::class);
        $limit = (int) $settingRepo->getValue('password_history_count', 0);

        if ($limit <= 0) {
            return;
        }

        $recentHashes = UserPasswordHistory::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('password');

        foreach ($recentHashes as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                throw ValidationException::withMessages([
                    'password' => ["No podés reutilizar ninguno de los últimos {$limit} passwords."],
                ]);
            }
        }
    }

    private function storePasswordHistory(User $user, string $hashedPassword): void
    {
        /** @var SystemSettingRepositoryInterface $settingRepo */
        $settingRepo = app(SystemSettingRepositoryInterface::class);
        $limit = (int) $settingRepo->getValue('password_history_count', 0);

        if ($limit <= 0) {
            return;
        }

        UserPasswordHistory::create([
            'user_id'  => $user->id,
            'password' => $hashedPassword,
        ]);

        // Purgar entradas que excedan el límite (conservar las N más recientes)
        $idsToKeep = UserPasswordHistory::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('id');

        UserPasswordHistory::where('user_id', $user->id)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }
}
