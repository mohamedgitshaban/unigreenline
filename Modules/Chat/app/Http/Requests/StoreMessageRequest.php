<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A message is text, files, or a voice recording. Text and files can be sent
 * together (the text acts as a caption); a voice recording is its own message.
 * Limits live in config/config.php.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * The route's `messages.create` permission and the participant-only
     * route binding on `{conversation}` are the gates.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.config('chat.message_max_length'), 'required_without_all:attachments,voice'],
            'attachments' => ['nullable', 'array', 'max:'.config('chat.attachments.max_files'), 'prohibits:voice'],
            'attachments.*' => [
                'file',
                'mimes:'.implode(',', config('chat.attachments.extensions')),
                'max:'.config('chat.attachments.max_size_kb'),
            ],
            'voice' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', config('chat.voice.mime_types')),
                'max:'.config('chat.voice.max_size_kb'),
                'prohibits:body,attachments',
            ],
            // The server can't read a recording's length, so the recorder reports it; it is only shown, never relied on.
            'voice_duration' => ['required_with:voice', 'nullable', 'integer', 'min:1', 'max:'.config('chat.voice.max_duration_seconds')],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without_all' => 'A message needs text, an attachment or a voice recording.',
            'voice.prohibits' => 'A voice message cannot include text or attachments.',
            'attachments.prohibits' => 'A voice message cannot include text or attachments.',
        ];
    }
}
