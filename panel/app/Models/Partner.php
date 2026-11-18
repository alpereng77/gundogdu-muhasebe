<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** İnşaat ortağı. Hisse oranı inşaat bazında tutulur (partner_project.share). */
class Partner extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->name;
    }

    protected $fillable = ['name', 'phone', 'note'];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('share');
    }
}
