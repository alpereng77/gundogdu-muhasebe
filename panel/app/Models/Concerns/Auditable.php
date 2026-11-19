<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/**
 * Model eklendiğinde, değiştiğinde veya silindiğinde işlem geçmişine kayıt yazar.
 * Modelde isteğe bağlı: auditLabel(), auditProjectId(), $auditHidden (kaydedilmeyecek alanlar).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('created', $m->auditValues($m->getAttributes())));
        static::updated(function ($m) {
            $changes = [];
            foreach ($m->getChanges() as $key => $new) {
                if (in_array($key, ['updated_at', 'created_at', 'remember_token'], true)) {
                    continue;
                }
                $changes[$key] = [$m->getOriginal($key), $new];
            }
            if ($changes) {
                $m->writeAudit('updated', $m->auditValues($changes, true));
            }
        });
        static::deleted(fn ($m) => $m->writeAudit('deleted', $m->auditValues($m->getAttributes())));
    }

    private function auditValues(array $values, bool $pairs = false): array
    {
        $hidden = array_merge(['password', 'remember_token', 'created_at', 'updated_at', 'items', 'file_path'], $this->auditHidden ?? [], method_exists($this, 'auditHiddenFor') ? $this->auditHiddenFor() : []);
        $out = [];
        foreach ($values as $key => $value) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            $out[$key] = $pairs ? array_map(fn ($v) => self::auditScalar($v), (array) $value) : self::auditScalar($value);
        }
        return $out;
    }

    private static function auditScalar($v)
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return is_string($v) ? mb_substr($v, 0, 300) : $v;
    }

    private function writeAudit(string $action, array $changes): void
    {
        if (AuditState::$paused) {
            return;
        }
        $user = auth()->user();
        ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'subject_type' => $this->auditType ?? strtolower(class_basename($this)),
            // Ayarlar gibi metin anahtarlı kayıtlarda kimlik yerine etiket (ayar adı) kullanılır.
            'subject_id' => is_numeric($this->getKey()) ? $this->getKey() : null,
            'label' => mb_substr((string) (method_exists($this, 'auditLabel') ? $this->auditLabel() : $this->getKey()), 0, 255),
            'project_id' => method_exists($this, 'auditProjectId') ? $this->auditProjectId() : ($this->project_id ?? null),
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
        ]);
    }

}
