<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Support\AuditLog;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, AuditLog::query()->with('user'))
                ->searchable(['action', 'ip_address'])
                ->sortable(['id' => 'id', 'action' => 'action', 'created_at' => 'created_at'])
                ->filter('action', fn ($query, $value) => $query->where('action', 'like', "{$value}%"))
                ->filter('user', fn ($query, $value) => $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$value}%")))
                ->filter('created_at', fn ($query, $value) => ColumnMatch::date($query, 'created_at', $value))
                ->filter('subject', function ($query, $value) {
                    $query->where(function ($query) use ($value) {
                        $query->where('subject_type', 'like', "%{$value}%")
                            ->orWhereRaw('CAST(subject_id AS CHAR) LIKE ?', ["%{$value}%"]);
                    });
                })
                ->defaultOrder('id')
                ->maxLength(100)
                ->transform(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                    'subject' => $log->auditable_type
                        ? ['type' => class_basename($log->auditable_type), 'id' => $log->auditable_id]
                        : null,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }
}
