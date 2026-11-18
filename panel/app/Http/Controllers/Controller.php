<?php

namespace App\Http\Controllers;

use App\Support\DataService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Kullanıcıya gösterilecek hata ile isteği sonlandırır. */
    protected function fail(string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }

    protected function requireEdit(Request $request, string $page): void
    {
        if (! $request->user()->canEdit($page)) {
            $this->fail('Bu işlem için yetkiniz bulunmuyor.', 403);
        }
    }

    protected function requireSuper(Request $request): void
    {
        if (! $request->user()->is_super_admin) {
            $this->fail('Bu işlem yalnızca süper admin tarafından yapılabilir.', 403);
        }
    }

    protected function requireProject(Request $request, int $projectId): void
    {
        if (! $request->user()->canAccessProject($projectId)) {
            $this->fail('Bu inşaatın verilerine erişim yetkiniz bulunmuyor.', 403);
        }
    }

    /** Güncel (kullanıcıya göre filtrelenmiş) veriyle başarılı yanıt. */
    protected function ok(Request $request, string $message, array $extra = []): JsonResponse
    {
        $user = $request->user()->fresh();
        return response()->json(['message' => $message, 'db' => DataService::snapshot($user), 'me' => DataService::me($user)] + $extra);
    }

    protected static function validDate($value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    protected static function money($value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = round((float) $value, 2);
        // decimal(15,2) sınırı: daha büyük tutarlar geçersiz sayılır.
        return is_finite($n) && abs($n) < 1e13 ? $n : null;
    }

    protected static function str($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected static function fmtMoney(float $n): string
    {
        return number_format($n, 2, ',', '.').' ₺';
    }
}
