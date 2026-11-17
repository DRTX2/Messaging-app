<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * Store an uploaded file and create an orphaned Attachment owned by $userId.
     * Requires `php artisan storage:link` for public URLs.
     */
    public function upload(UploadedFile $file, int $userId): Attachment
    {
        // Extension is guessed from file content, never trusted from the client
        $filename = Str::uuid().'.'.$file->extension();

        return Attachment::create([
            'user_id' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'path' => $file->storeAs('attachments', $filename, 'public'),
            'size_bytes' => $file->getSize(),
            'message_id' => null,
        ]);
    }

    /**
     * Link the caller's own orphaned attachments to a message (never someone else's).
     */
    public function linkAttachmentsToMessage(int $messageId, int $userId, array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }

        Attachment::whereIn('id', $attachmentIds)
            ->where('user_id', $userId)
            ->whereNull('message_id')
            ->update(['message_id' => $messageId]);
    }
}
