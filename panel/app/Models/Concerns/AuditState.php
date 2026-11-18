<?php

namespace App\Models\Concerns;

/** Toplu işlemlerde (yedekten geri yükleme) işlem geçmişi tek tek yazılmaz. */
class AuditState
{
    public static bool $paused = false;
}
