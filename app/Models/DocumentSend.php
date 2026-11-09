<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email of documents sent to a workspace from /admin: who sent it, to
 * whom, what it said, and a copy of each PDF exactly as it went out (on
 * the private disk), so staff can see what a customer has and resend it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $sent_by_id
 * @property list<string> $recipients
 * @property list<string>|null $bcc the staff member, when they asked for a copy
 * @property string $subject
 * @property string $message Markdown, as sent
 * @property list<array{name: string, kind: string, templateId: int|null, filename: string, path: string, size: int}> $documents
 * @property CarbonImmutable $created_at
 * @property-read Workspace $workspace
 * @property-read User|null $sentBy
 */
class DocumentSend extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipients' => 'array', 'bcc' => 'array', 'documents' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    /**
     * As the admin pages list it.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'workspace' => $this->relationLoaded('workspace') ? ['id' => $this->workspace->id, 'name' => $this->workspace->name] : null,
            'sentBy' => $this->sentBy->name ?? 'A former admin',
            'recipients' => $this->recipients,
            'bcc' => $this->bcc ?? [],
            'subject' => $this->subject,
            'message' => $this->message,
            'documents' => collect($this->documents)->values()->map(fn (array $d, int $i) => [
                'name' => $d['name'],
                'kind' => $d['kind'],
                'templateId' => $d['templateId'],
                'filename' => $d['filename'],
                'size' => $d['size'],
                'url' => route('admin.document-sends.file', [$this, $i]),
            ])->all(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
