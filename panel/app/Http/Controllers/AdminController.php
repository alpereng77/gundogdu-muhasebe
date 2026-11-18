<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Kullanıcı ve rol yönetimi — yalnızca süper admin. */
class AdminController extends Controller
{
    // ---------------------------------------------------------------- Kullanıcılar

    public function storeUser(Request $request)
    {
        $this->requireSuper($request);
        $data = $this->userData($request, null);
        if (mb_strlen($data['password'] ?? '') < 8) {
            $this->fail('Şifre en az 8 karakter olmalıdır.');
        }
        $projects = $data['projects'];
        unset($data['projects']);
        DB::transaction(function () use ($data, $projects) {
            User::create($data)->projects()->sync($projects);
        });

        return $this->ok($request, 'Kullanıcı oluşturuldu.');
    }

    public function updateUser(Request $request, User $user)
    {
        $this->requireSuper($request);
        $data = $this->userData($request, $user);
        $self = $request->user()->id === $user->id;

        if ($self && (! $data['is_active'] || ! $data['is_super_admin'])) {
            $this->fail('Kendi hesabınızı pasifleştiremez veya süper admin yetkinizi kaldıramazsınız.');
        }
        if ($user->is_super_admin && (! $data['is_super_admin'] || ! $data['is_active']) && $this->activeSuperCount($user->id) === 0) {
            $this->fail('Sistemde en az bir aktif süper admin bulunmalıdır.');
        }
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        } elseif (mb_strlen($data['password']) < 8) {
            $this->fail('Şifre en az 8 karakter olmalıdır.');
        }
        $projects = $data['projects'];
        unset($data['projects']);
        DB::transaction(function () use ($user, $data, $projects) {
            $user->update($data);
            $user->projects()->sync($projects);
        });
        // Pasifleştirilen veya şifresi değiştirilen kullanıcının açık oturumlarını kapat.
        if (! $user->is_active || isset($data['password'])) {
            if (isset($data['password'])) {
                $user->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();
            }
            DB::table('sessions')->where('user_id', $user->id)->when($self, fn ($q) => $q->where('id', '!=', $request->session()->getId()))->delete();
        }

        return $this->ok($request, 'Kullanıcı güncellendi.');
    }

    public function destroyUser(Request $request, User $user)
    {
        $this->requireSuper($request);
        if ($request->user()->id === $user->id) {
            $this->fail('Kendi hesabınızı silemezsiniz.');
        }
        if ($user->is_super_admin && $this->activeSuperCount($user->id) === 0) {
            $this->fail('Sistemde en az bir aktif süper admin bulunmalıdır.');
        }
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();

        return $this->ok($request, 'Kullanıcı silindi.');
    }

    private function activeSuperCount(int $exceptId): int
    {
        return User::where('is_super_admin', true)->where('is_active', true)->where('id', '!=', $exceptId)->count();
    }

    private function userData(Request $request, ?User $user): array
    {
        $name = self::str($request->input('name'));
        $email = Str::lower(self::str($request->input('email')));
        $super = $request->boolean('super');
        $roleId = $request->input('role') ? (int) $request->input('role') : null;

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Ad soyad ve geçerli bir e-posta giriniz.');
        }
        if (User::where('email', $email)->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists()) {
            $this->fail('Bu e-posta adresi başka bir kullanıcıya ait.');
        }
        if ($roleId !== null && ! Role::whereKey($roleId)->exists()) {
            $this->fail('Seçilen rol bulunamadı.');
        }
        if (! $super && $roleId === null) {
            $this->fail('Süper admin olmayan kullanıcıya bir rol seçiniz.');
        }
        $projects = array_values(array_unique(array_map('intval', (array) $request->input('projects', []))));
        $projects = Project::whereIn('id', $projects)->pluck('id')->all();
        $all = $request->boolean('allProjects');
        if (! $super && ! $all && ! $projects) {
            $this->fail('En az bir inşaat seçiniz veya “Tüm inşaatlar” seçeneğini işaretleyiniz.');
        }

        return [
            'name' => mb_substr($name, 0, 150), 'email' => $email, 'role_id' => $roleId,
            'is_super_admin' => $super, 'all_projects' => $all, 'is_active' => $request->boolean('active'),
            'password' => (string) $request->input('password', ''), 'projects' => $projects,
        ];
    }

    // ---------------------------------------------------------------- Roller

    public function storeRole(Request $request)
    {
        $this->requireSuper($request);
        Role::create($this->roleData($request, null));

        return $this->ok($request, 'Rol oluşturuldu.');
    }

    public function updateRole(Request $request, Role $role)
    {
        $this->requireSuper($request);
        $role->update($this->roleData($request, $role));

        return $this->ok($request, 'Rol güncellendi.');
    }

    public function destroyRole(Request $request, Role $role)
    {
        $this->requireSuper($request);
        if ($role->users()->exists()) {
            $this->fail('Bu role atanmış kullanıcılar var. Önce kullanıcıların rolünü değiştiriniz.');
        }
        $role->delete();

        return $this->ok($request, 'Rol silindi.');
    }

    private function roleData(Request $request, ?Role $role): array
    {
        $name = self::str($request->input('name'));
        if ($name === '') {
            $this->fail('Rol adını giriniz.');
        }
        if (Role::where('name', $name)->when($role, fn ($q) => $q->where('id', '!=', $role->id))->exists()) {
            $this->fail('Bu rol adı zaten kayıtlı.');
        }
        $permissions = Access::sanitize((array) $request->input('permissions', []));
        if (! $permissions) {
            $this->fail('Role en az bir menü yetkisi veriniz.');
        }

        return ['name' => mb_substr($name, 0, 100), 'description' => mb_substr(self::str($request->input('description')), 0, 255), 'permissions' => $permissions];
    }
}
