<?php

namespace App\Models;

use App\Support\Audit\AuditContext;
use App\Support\Extensions\Extensions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Something ServerOS staff changed from /admin, or did in a customer's
 * workspace while viewing as them: who, what, and what it was about (a
 * user, a workspace, a doc page, a package's own model… or nothing, like a
 * test email).
 * Rows are never edited or deleted from the app.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string|null $admin_name as they were called when they did it
 * @property string|null $admin_email
 * @property int|null $impersonation_session_id the View as it happened in
 * @property string $action e.g. workspace.trial_extended, user.two_factor_reset
 * @property string|null $target_type a key of targets() (TARGETS, or a package's), or null
 * @property int|null $target_id
 * @property string|null $target_label its name or title at the time
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 * @property-read User|null $admin
 * @property-read ImpersonationSession|null $impersonation
 */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    /**
     * What an action can be about, by the short name stored with it: the
     * panel's own models. A package adds its own with
     * Extensions::addAuditTarget; targets() has both.
     */
    public const array TARGETS = [
        'user' => User::class,
        'workspace' => Workspace::class,
        'doc_page' => DocPage::class,
        'doc_section' => DocSection::class,
        'document_template' => DocumentTemplate::class,
    ];

    /** Staff history is kept two years, then pruned (logs:prune). */
    public const int KEEP_DAYS = 730;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // The audit trail only grows. (Deleting a user still blanks
        // admin_id in the database; the name snapshot stays.)
        static::updating(fn () => throw new LogicException('Admin actions are never edited.'));
        static::deleting(fn () => throw new LogicException('Admin actions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return BelongsTo<ImpersonationSession, $this> */
    public function impersonation(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(User $admin, string $action, ?Model $target = null, array $meta = [], ?ImpersonationSession $session = null): void
    {
        $current = $session->id ?? AuditContext::sessionId();
        self::write($admin->id, $admin->name, $admin->email, $current, $action, $target, $meta, here: true);

        // What changes how they sign in, they see too, as ServerOS Support.
        if ($target instanceof User && $current === null) {
            AccountEvent::fromStaff($admin, $action, $target, $meta);
        }
    }

    /**
     * Something done in a View as session, by its admin: as they are now,
     * or as the session remembers them once their account is gone.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function recordForSession(ImpersonationSession $session, string $action, ?Model $target = null, array $meta = []): void
    {
        $admin = $session->admin;
        // The IP and browser are the admin's only when this is their
        // request: not a machine's enrolling with a command they left, nor
        // the scheduler closing a stale session.
        self::write($admin?->id, $admin->name ?? $session->admin_name, $admin->email ?? $session->admin_email, $session->id, $action, $target, $meta, here: AuditContext::sessionId() === $session->id);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  bool  $here  whether the admin made the request being handled
     */
    private static function write(?int $adminId, string $adminName, string $adminEmail, ?int $session, string $action, ?Model $target, array $meta, bool $here): void
    {
        self::query()->create([
            'admin_id' => $adminId,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'impersonation_session_id' => $session,
            'action' => $action,
            'target_type' => $target === null ? null : self::typeOf($target),
            'target_id' => $target?->getKey(),
            'target_label' => $target === null ? null : mb_substr(self::labelOf($target), 0, 160),
            'meta' => $meta ?: null,
            'ip' => $here ? AuditContext::ip() : null,
            'user_agent' => $here ? AuditContext::userAgent() : null,
        ]);
        AuditContext::markRecorded();
    }

    /**
     * An edit just saved: `<type>.updated` with the fields that changed, or
     * `<type>.published` / `.unpublished` when it went live or came down
     * (pass whether it was live before and after). Nothing when the save
     * changed nothing.
     */
    public static function recordSave(User $admin, Model $target, ?bool $wasLive = null, ?bool $isLive = null): void
    {
        $changed = self::changed($target);
        if ($changed === []) {
            AuditContext::nothingChanged();

            return;
        }
        $verb = match (true) {
            $wasLive === null || $wasLive === $isLive => 'updated',
            $isLive => 'published',
            default => 'unpublished',
        };

        self::record($admin, self::typeOf($target).'.'.$verb, $target, [
            'changed' => array_values(array_diff($changed, ['published', 'published_at'])),
        ]);
    }

    /**
     * Which fields a save just changed, for an "updated" entry, the
     * timestamps left out.
     *
     * @return list<string>
     */
    private static function changed(Model $model): array
    {
        return array_values(array_diff(array_keys($model->getChanges()), ['updated_at', 'created_at', 'updated_by_id']));
    }

    /**
     * Everything an action can be about, by the name stored with it: the
     * panel's (TARGETS), then the packages'.
     *
     * @return array<string, class-string<Model>>
     */
    public static function targets(): array
    {
        return [...self::TARGETS, ...array_map(fn (array $target) => $target['class'], app(Extensions::class)->auditTargets())];
    }

    /** The short name stored for a model, e.g. doc_page. */
    public static function typeOf(Model $target): string
    {
        $type = array_search($target::class, self::targets(), true);
        if ($type === false) {
            throw new InvalidArgumentException('Add '.$target::class.' to AdminAction::TARGETS (or a package\'s, with Extensions::addAuditTarget) first.');
        }

        return $type;
    }

    /** What to call it in the history, as it is right now. */
    private static function labelOf(Model $target): string
    {
        foreach (app(Extensions::class)->auditTargets() as $package) {
            if ($target::class === $package['class']) {
                return $package['label'] === null ? "#{$target->getKey()}" : (string) ($package['label'])($target);
            }
        }

        return (string) match (true) {
            $target instanceof User, $target instanceof Workspace => $target->name,
            $target instanceof DocPage, $target instanceof DocSection => $target->title,
            $target instanceof DocumentTemplate => $target->name,
            default => "#{$target->getKey()}",
        };
    }

    /**
     * Actions about this user or workspace.
     *
     * @param  Builder<AdminAction>  $query
     */
    public function scopeAbout(Builder $query, Model $target): void
    {
        $query->where('target_type', self::typeOf($target))->where('target_id', $target->getKey());
    }

    /** Who did it, even once their account is gone. */
    public function adminName(): string
    {
        return $this->admin->name ?? $this->admin_name ?? 'A former admin';
    }

    /**
     * The history shown on a user's or workspace's admin page.
     *
     * @return list<array<string, mixed>>
     */
    public static function historyFor(User|Workspace $target): array
    {
        return array_values(self::query()
            ->about($target)
            ->with('admin:id,name,email')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AdminAction $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'admin' => $a->adminName(),
                'meta' => $a->meta ?? [],
                'createdAt' => $a->created_at->toIso8601String(),
            ])
            ->all());
    }
}
