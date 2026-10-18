<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Chat\Models\MessageAttachment;

/**
 * `{messageAttachment}` is bound in RouteServiceProvider to files in the
 * caller's own conversations only, so someone else's id is a 404 here.
 */
class MessageAttachmentController extends Controller
{
    /**
     * Images and voice notes are served inline (the client plays them);
     * every other file is sent as a download. `nosniff` stops a browser
     * second-guessing the type recorded at upload.
     */
    public function show(MessageAttachment $messageAttachment)
    {
        abort_unless(Storage::exists($messageAttachment->path), 404, 'This file is no longer available.');

        $headers = ['Content-Type' => $messageAttachment->mime_type, 'X-Content-Type-Options' => 'nosniff'];

        return $messageAttachment->isInline()
            ? Storage::response($messageAttachment->path, $messageAttachment->original_name, $headers)
            : Storage::download($messageAttachment->path, $messageAttachment->original_name, $headers);
    }
}
