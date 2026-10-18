<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\StoreMessageRequest;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\SendMessageService;

/**
 * `{conversation}` is bound in RouteServiceProvider to the caller's own
 * conversations only, so someone else's id is a 404 here.
 */
class MessageController extends Controller
{
    private const RELATIONS = ['sender', 'attachments'];

    public function __construct(private readonly SendMessageService $sendMessage) {}

    /**
     * A conversation's messages, newest first, paged by cursor so that
     * messages arriving while someone scrolls back never shift the pages.
     * `?after={message id}` returns only what came after that message, which
     * is how a client picks up new messages.
     */
    public function index(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'after' => ['sometimes', 'ulid'],
        ]);

        $messages = $conversation->messages()
            ->with(self::RELATIONS)
            ->when($validated['after'] ?? null, fn ($query, string $after) => $query->where('id', '>', $after))
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 30)
            ->withQueryString();

        return MessageResource::collection($messages);
    }

    /**
     * Sends text, files, or a voice recording (multipart form data).
     */
    public function store(StoreMessageRequest $request, Conversation $conversation)
    {
        $message = $this->sendMessage->send(
            $conversation,
            $request->user(),
            $request->validated('body'),
            $request->file('attachments', []),
            $request->file('voice'),
            $request->filled('voice_duration') ? $request->integer('voice_duration') : null,
        );

        return (new MessageResource($message->load(self::RELATIONS)))->response()->setStatusCode(201);
    }
}
