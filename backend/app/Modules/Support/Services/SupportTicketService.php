<?php

namespace App\Modules\Support\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportTicketService
{
    public const FILE_RULES = ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip'];
    public const MAX_FILES  = 5;

    /** @param UploadedFile[] $files */
    public function addMessage(SupportTicket $ticket, ?User $user, string $senderType, string $body, bool $internal = false, array $files = []): SupportTicketMessage
    {
        $message = $ticket->messages()->create([
            'user_id'     => $user?->id,
            'sender_type' => $senderType,
            'body'        => $body,
            'is_internal' => $internal,
        ]);

        foreach ($files as $file) {
            $message->attachments()->create([
                'ticket_id' => $ticket->id,
                'filename'  => mb_substr($file->getClientOriginalName(), 0, 255),
                'path'      => $file->store("support-tickets/{$ticket->id}", 'local'),
                'mime'      => $file->getClientMimeType(),
                'size'      => $file->getSize(),
            ]);
        }
        return $message;
    }

    /** A line in the thread that records a change ("Status changed to Resolved"). */
    public function event(SupportTicket $ticket, ?User $user, string $text, bool $internal = false): void
    {
        $this->addMessage($ticket, $user, 'system', $text, $internal);
    }

    public function download(SupportTicketAttachment $a)
    {
        abort_unless(Storage::disk('local')->exists($a->path), 404, 'File no longer available.');
        return Storage::disk('local')->download($a->path, $a->filename);
    }

    public function thread(SupportTicket $ticket, bool $forStaff): array
    {
        return $ticket->messages()
            ->when(!$forStaff, fn ($q) => $q->where('is_internal', false))
            ->with(['user:id,name', 'attachments:id,message_id,filename,mime,size'])
            ->orderBy('id')->get()
            ->map(fn (SupportTicketMessage $m) => [
                'id'          => $m->id,
                'sender_type' => $m->sender_type,
                'is_internal' => $m->is_internal,
                // company users see "Support Team" for staff replies; staff see who actually replied
                'author'      => $m->sender_type === 'system' ? null
                                 : ($m->sender_type === 'support' && !$forStaff ? 'Support Team' : ($m->user?->name ?? 'Unknown')),
                'body'        => $m->body,
                'created_at'  => $m->created_at?->toIso8601String(),
                'attachments' => $m->attachments->map(fn ($a) => ['id' => $a->id, 'filename' => $a->filename, 'mime' => $a->mime, 'size' => $a->size]),
            ])->all();
    }

    public function summary(SupportTicket $t, bool $forStaff): array
    {
        $out = [
            'id'            => $t->id,
            'ticket_no'     => $t->ticket_no,
            'subject'       => $t->subject,
            'description'   => $t->description,
            'priority'      => $t->priority,
            'status'        => $t->status,
            'department'    => $t->department ? ['id' => $t->department->id, 'name' => $t->department->name] : null,
            'created_by'    => $t->creator?->name,
            'assigned_to'   => $t->assignee ? ['id' => $t->assignee->id, 'name' => $forStaff ? $t->assignee->name : 'Support Team'] : null,
            'last_reply_by' => $t->last_reply_by,
            'last_reply_at' => $t->last_reply_at?->toIso8601String(),
            'created_at'    => $t->created_at?->toIso8601String(),
            'resolved_at'   => $t->resolved_at?->toIso8601String(),
            'rating'        => $t->rating,
            'rating_feedback' => $t->rating_feedback,
        ];
        if ($forStaff) {
            $out += [
                'company'           => $t->company ? ['id' => $t->company->id, 'name' => $t->company->name] : null,
                'due_at'            => $t->due_at?->toIso8601String(),
                'first_response_at' => $t->first_response_at?->toIso8601String(),
                'overdue'           => $t->isOverdue(),
            ];
        }
        return $out;
    }
}
