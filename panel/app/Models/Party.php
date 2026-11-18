<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->code.' · '.$this->name;
    }

    protected $fillable = ['name', 'code', 'type', 'tax'];
}
