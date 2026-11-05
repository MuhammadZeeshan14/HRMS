<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditService
{
    public function record(string $action, string $type, ?int $id, array $old = [], array $new = []): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'old_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null, 'ip_address' => request()?->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
