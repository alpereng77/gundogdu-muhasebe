<?php

namespace App\Http\Controllers;

use App\Support\Access;
use App\Support\DataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PageController extends Controller
{
    public function show(Request $request, string $key)
    {
        $user = $request->user();
        if (! $user->canView($key)) {
            $first = $user->firstPage();
            if (! $first) {
                Auth::logout();
                return redirect('/giris')->with('error', 'Hesabınıza menü yetkisi tanımlanmamış.');
            }
            return redirect($first)->with('notice', 'Bu menüye erişim yetkiniz bulunmuyor.');
        }

        $pages = [];
        foreach (Access::allPages() as $k => $p) {
            if ($user->canView($k)) {
                $pages[] = [$p['path'], $p['label'], $p['icon'], $k];
            }
        }

        return view('app', [
            'label' => Access::allPages()[$key]['label'],
            'boot' => [
                'db' => DataService::snapshot($user),
                'me' => DataService::me($user),
                'pages' => $pages,
                'pageKey' => $key,
                'notice' => session('notice'),
                'meta' => [
                    'payMethods' => Access::PAY_METHODS,
                    'chequeStatuses' => Access::CHEQUE_STATUSES,
                    'chequeFlow' => Access::CHEQUE_FLOW,
                    'taxStatuses' => Access::TAX_STATUSES,
                    'menu' => collect(Access::PAGES)->map(fn ($p, $k) => ['key' => $k, 'label' => $p['label'], 'editable' => $p['editable']])->values(),
                ],
            ],
        ]);
    }
}
