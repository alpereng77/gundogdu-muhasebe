<?php

namespace App\Models;

use App\Support\Access;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->name.' ('.$this->email.')';
    }

    protected $fillable = ['name', 'email', 'password', 'role_id', 'is_super_admin', 'all_projects', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'all_projects' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /** Menü anahtarı → 'view' | 'edit' */
    public function permissionMap(): array
    {
        if ($this->is_super_admin) {
            $map = [];
            foreach (Access::allPages() as $key => $page) {
                $map[$key] = 'edit';
            }
            return $map;
        }
        return Access::sanitize($this->role?->permissions ?? []);
    }

    public function canView(string $page): bool
    {
        return isset($this->permissionMap()[$page]);
    }

    public function canEdit(string $page): bool
    {
        return ($this->permissionMap()[$page] ?? null) === 'edit';
    }

    /** Herhangi bir cari menüsünde düzenleme yetkisi var mı? */
    public function canEditParties(): bool
    {
        return $this->canEdit('cari-hesaplar') || $this->canEdit('alicilar') || $this->canEdit('saticilar');
    }

    public function seesAllProjects(): bool
    {
        return $this->is_super_admin || $this->all_projects;
    }

    /** Erişebildiği inşaat id'leri (sıralı). */
    private ?array $projectIdsCache = null;

    public function projectIds(): array
    {
        return $this->projectIdsCache ??= $this->seesAllProjects()
            ? Project::orderBy('id')->pluck('id')->all()
            : $this->projects()->orderBy('projects.id')->pluck('projects.id')->all();
    }

    public function canAccessProject(int $projectId): bool
    {
        return in_array($projectId, $this->projectIds(), true);
    }

    /** Görebildiği ilk menü (yetkisiz sayfadan yönlendirme için). */
    public function firstPage(): ?string
    {
        foreach (Access::allPages() as $key => $page) {
            if ($this->canView($key)) {
                return $page['path'];
            }
        }
        return null;
    }
}
