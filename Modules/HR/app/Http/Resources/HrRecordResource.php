<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plain record as the API shows it: its columns (cast to their natural types) and any relations loaded with it.
 * Date-only columns are written as plain dates. The company id is not repeated.
 */
class HrRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->toArray();
        unset($data['tenant_id']);

        // Date-only columns are written as plain dates, not as midnight timestamps.
        foreach ($this->resource->getCasts() as $column => $cast) {
            if ($cast === 'date' && array_key_exists($column, $data)) {
                $data[$column] = $this->resource->{$column}?->toDateString();
            }
        }

        return $data;
    }
}
