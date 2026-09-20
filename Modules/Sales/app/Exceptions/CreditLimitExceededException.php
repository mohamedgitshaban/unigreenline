<?php

namespace Modules\Sales\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CreditLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $customerId,
        public readonly float $creditLimit,
        public readonly float $currentBalance,
        public readonly float $orderTotal,
    ) {
        parent::__construct('This order would push the customer over their credit limit.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'customer_id' => $this->customerId,
            'credit_limit' => $this->creditLimit,
            'current_balance' => $this->currentBalance,
            'order_total' => $this->orderTotal,
        ], 422);
    }
}
