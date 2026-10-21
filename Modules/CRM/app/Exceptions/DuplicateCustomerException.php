<?php

namespace Modules\CRM\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a customer being created matches one that already exists. Carries the matches (and whom
 * they belong to) so the client can show them and, where allowed, retry with `allow_duplicate`.
 */
class DuplicateCustomerException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $duplicates
     */
    public function __construct(private readonly array $duplicates, private readonly bool $canOverride)
    {
        $owner = collect($duplicates)->first(fn (array $d) => ! $d['owned_by_me'] && $d['sales_rep'] !== null);

        parent::__construct($owner
            ? "A customer like this already exists and belongs to {$owner['sales_rep']['name']}."
            : 'A customer like this already exists.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['customer' => [$this->getMessage()]],
            'duplicates' => $this->duplicates,
            'can_override' => $this->canOverride,
        ], 422);
    }
}
