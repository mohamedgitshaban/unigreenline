<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    /**
     * The route's `conversations.create` permission is the only gate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only an active colleague in the same tenant can be chatted with.
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'user_id' => [
                'required', 'string',
                Rule::notIn([$user->id]),
                Rule::exists('users', 'id')->where('tenant_id', $user->tenant_id)->where('status', 'active'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.not_in' => 'You cannot start a conversation with yourself.',
        ];
    }
}
