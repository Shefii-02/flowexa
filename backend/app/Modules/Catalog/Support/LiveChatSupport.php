<?php

namespace App\Modules\Catalog\Support;

use App\Models\ChatWidget;
use App\Models\User;
use App\Models\WidgetChatStaff;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Shared rules for website live chat: who gets alerted, per-staff limits, attachments, message shape. */
class LiveChatSupport
{
    public const PERMISSION = 'widget_chat.handle';
    public const MAX_FILE_KB = 2048; // 2 MB

    private const ALLOWED = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'audio' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'video/webm', 'video/mp4'],
        'file'  => ['application/pdf', 'text/plain', 'text/csv', 'application/zip',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    /** Live chats this staff member is handling right now. */
    public static function handling(int $userId): int
    {
        return WidgetConversation::where('assigned_user_id', $userId)->where('live_status', 'live')->count();
    }

    public static function limit(ChatWidget $widget): int
    {
        return max(1, (int) ($widget->live_max_per_staff ?: 5));
    }

    public static function isMuted(int $userId): bool
    {
        return (bool) WidgetChatStaff::where('user_id', $userId)->value('muted');
    }

    /** Staff who should hear about a new live chat: have access, not muted, and still under their limit. */
    public static function alertRecipients(ChatWidget $widget): array
    {
        $muted = WidgetChatStaff::where('company_id', $widget->company_id)->where('muted', true)->pluck('user_id')->all();
        $limit = self::limit($widget);

        return User::where('company_id', $widget->company_id)->where('is_active', true)
            ->whereNotIn('id', $muted)->get()
            ->filter(fn (User $u) => $u->hasPermission(self::PERMISSION) && self::handling($u->id) < $limit)
            ->pluck('id')->all();
    }

    /** Validate + store an upload for a conversation. @return array<string,mixed> columns for WidgetMessage */
    public static function storeUpload(UploadedFile $file, WidgetConversation $convo, array $types = ['image', 'audio', 'file']): array
    {
        abort_unless($file->isValid(), 422, 'Upload failed. Please try again.');
        abort_if($file->getSize() > self::MAX_FILE_KB * 1024, 422, 'File is too large — the limit is 2 MB.');

        $mime = strtolower((string) $file->getMimeType());
        $type = collect(self::ALLOWED)->search(fn ($list) => in_array($mime, $list, true));
        abort_if($type === false || !in_array($type, $types, true), 422, 'This file type is not allowed.');

        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = $file->storeAs(self::dir($convo), Str::uuid() . '.' . preg_replace('/[^a-z0-9]/', '', $ext), 'public');

        return [
            'type' => $type, 'attachment_path' => $path, 'attachment_mime' => $mime,
            'attachment_name' => Str::limit($file->getClientOriginalName(), 190, ''), 'attachment_size' => $file->getSize(),
        ];
    }

    public static function dir(WidgetConversation $convo): string
    {
        return "widget-chat/{$convo->company_id}/{$convo->id}";
    }

    /** Remove every uploaded file of a conversation (called when it is deleted). */
    public static function purgeFiles(WidgetConversation $convo): void
    {
        Storage::disk('public')->deleteDirectory(self::dir($convo));
    }

    /** The JSON shape both the portal/mobile app and the embedded widget receive. */
    public static function format(WidgetMessage $m): array
    {
        $reply = $m->reply_to_id ? WidgetMessage::find($m->reply_to_id) : null;
        return [
            'id' => $m->id, 'role' => $m->role, 'type' => $m->type ?: 'text', 'text' => $m->text,
            'url' => $m->attachment_path ? url(Storage::disk('public')->url($m->attachment_path)) : null,
            'file_name' => $m->attachment_name, 'mime' => $m->attachment_mime, 'size' => $m->attachment_size,
            'reply_to' => $reply ? ['id' => $reply->id, 'role' => $reply->role, 'type' => $reply->type ?: 'text', 'text' => self::preview($reply)] : null,
            'created_at' => $m->created_at,
        ];
    }

    public static function preview(WidgetMessage $m): string
    {
        if (trim((string) $m->text) !== '') return Str::limit($m->text, 80);
        return match ($m->type) { 'image' => '📷 Photo', 'audio' => '🎤 Voice message', 'file' => '📎 ' . ($m->attachment_name ?: 'File'), default => '' };
    }
}
