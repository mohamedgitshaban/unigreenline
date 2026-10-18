<?php

return [
    'name' => 'Chat',

    // Longest text message, in characters.
    'message_max_length' => 5000,

    // Files attached to a message. Checked against what the file really is, not what it is called.
    'attachments' => [
        'max_files' => 10,
        'max_size_kb' => 10240,
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'],
    ],

    // A recorded voice message. Browsers record webm or ogg (Chrome, Firefox) or mp4 (Safari).
    'voice' => [
        'max_size_kb' => 10240,
        'max_duration_seconds' => 600,
        'mime_types' => ['audio/*', 'video/webm', 'video/mp4', 'application/ogg'],
    ],
];
