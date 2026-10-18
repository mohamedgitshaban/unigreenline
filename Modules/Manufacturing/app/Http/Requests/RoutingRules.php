<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;

class RoutingRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function operation(string $tenantId, string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            "{$prefix}sequence" => [$presence, 'integer', 'min:1', 'max:65535'],
            "{$prefix}name" => [$presence, 'string', 'max:255'],
            "{$prefix}work_center_id" => [$presence, 'string', Rule::exists('work_centers', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            "{$prefix}setup_minutes" => ['sometimes', 'integer', 'min:0', 'max:100000'],
            "{$prefix}run_minutes" => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            "{$prefix}cleanup_minutes" => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // An operation id (or, when a routing is created with its operations, the sequence of an earlier one).
            "{$prefix}depends_on_id" => ['nullable'],
            "{$prefix}instructions" => ['nullable', 'string', 'max:5000'],
        ];
    }
}
