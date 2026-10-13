<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = AuditLog::with('user')
            ->when($request->string('action')->trim()->value(), fn ($q, $a) => $q->where('action', 'like', "{$a}%"))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.audit-logs', compact('logs'));
    }
}
