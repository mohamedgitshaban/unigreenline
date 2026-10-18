<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\StoreConversationRequest;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationParticipant;
use Modules\Chat\Services\StartConversationService;
use Modules\Core\Models\User;

/**
 * `{conversation}` is bound in RouteServiceProvider to the caller's own
 * conversations only, so someone else's id is a 404 here.
 */
class ConversationController extends Controller
{
    private const RELATIONS = ['participants.user', 'latestMessage.sender', 'latestMessage.attachments'];

    public function __construct(private readonly StartConversationService $startConversation) {}

    /**
     * The caller's conversations, most recently active first.
     */
    public function index(Request $request)
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        $user = $request->user();

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->withUnreadCountFor($user)
            ->with(self::RELATIONS)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation)
    {
        return new ConversationResource($this->withDetails($conversation, $request->user()));
    }

    /**
     * Opens the conversation with another user. Asking again for the same
     * person returns the existing one (200) instead of creating a second.
     */
    public function store(StoreConversationRequest $request)
    {
        $user = $request->user();

        [$conversation, $created] = $this->startConversation->start($user, User::query()->findOrFail($request->validated('user_id')));

        return (new ConversationResource($this->withDetails($conversation, $user)))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Marks everything in the conversation as read for the caller.
     */
    public function read(Request $request, Conversation $conversation)
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_message_id' => $conversation->messages()->max('id')]);

        return response()->noContent();
    }

    private function withDetails(Conversation $conversation, User $user): Conversation
    {
        return $conversation->load(self::RELATIONS)->loadUnreadCountFor($user);
    }
}
