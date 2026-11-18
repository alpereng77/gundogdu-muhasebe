<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

/** İşlem geçmişi: kullanıcı yalnızca erişebildiği inşaatların kayıtlarını görür; genel kayıtlar (cari, ayar, kullanıcı) süper admine. */
class LogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $type = (string) $request->query('type', '');
        $subject = (int) $request->query('id', 0);

        // Tek kaydın geçmişi (fatura detayı): faturayı görebilen kullanıcıya açıktır.
        if ($type === 'invoice' && $subject) {
            $invoice = \App\Models\Invoice::find($subject);
            $projectId = $invoice?->project_id ?? ActivityLog::where('subject_type', 'invoice')->where('subject_id', $subject)->value('project_id');
            if (! $user->canView('faturalar') || ! $projectId || ! $user->canAccessProject((int) $projectId)) {
                $this->fail('Bu kaydın geçmişini görme yetkiniz yok.', 403);
            }
            $q = ActivityLog::where(fn ($w) => $w->where(fn ($x) => $x->where('subject_type', 'invoice')->where('subject_id', $subject))
                ->orWhere(fn ($x) => $x->where('subject_type', 'payment')->where('changes->invoice_id', $subject)));
            return response()->json(['logs' => $q->orderByDesc('id')->limit(100)->get()->map(fn ($l) => $this->row($l)), 'more' => false]);
        }

        if (! $user->canView('islem-gecmisi')) {
            $this->fail('İşlem geçmişini görme yetkiniz yok.', 403);
        }
        $q = ActivityLog::query();
        if (! $user->is_super_admin) {
            $q->whereIn('project_id', $user->projectIds());
        }
        if ($type !== '') {
            $q->where('subject_type', $type);
        }
        if ($request->filled('user')) {
            $q->where('user_id', (int) $request->query('user'));
        }
        if ($request->filled('action')) {
            $q->where('action', (string) $request->query('action'));
        }
        if ($request->filled('project') && $request->query('project') !== 'all') {
            $q->where('project_id', (int) $request->query('project'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (self::validDate($request->query($key))) {
                $q->whereDate('created_at', $op, $request->query($key));
            }
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->where('label', 'like', '%'.$s.'%')->orWhere('user_name', 'like', '%'.$s.'%'));
        }
        $before = (int) $request->query('before', 0);
        if ($before) {
            $q->where('id', '<', $before);
        }
        $rows = $q->orderByDesc('id')->limit(51)->get();

        return response()->json([
            'logs' => $rows->take(50)->map(fn ($l) => $this->row($l))->values(),
            'more' => $rows->count() > 50,
            'users' => ActivityLog::query()->when(! $user->is_super_admin, fn ($w) => $w->whereIn('project_id', $user->projectIds()))
                ->whereNotNull('user_id')->select('user_id', 'user_name')->distinct()->orderBy('user_name')->get(),
        ]);
    }

    private function row(ActivityLog $l): array
    {
        return [
            'id' => $l->id, 'at' => $l->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'), 'user' => $l->user_name ?? 'Sistem',
            'action' => $l->action, 'type' => $l->subject_type, 'subject' => $l->subject_id, 'label' => $l->label,
            'project' => $l->project_id, 'changes' => $l->changes ?? [],
        ];
    }
}
