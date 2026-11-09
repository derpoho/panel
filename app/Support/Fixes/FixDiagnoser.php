<?php

namespace App\Support\Fixes;

use App\Models\AgentFix;
use App\Support\Ai\AiProviders;

/**
 * Asks the model what's wrong. It gets the evidence and the allowlist, and
 * has to answer in a fixed JSON shape: a title, the cause, an explanation
 * that points at the lines that show it, how sure it is, and at most a few
 * steps. Whatever it says, the steps go through FixActions before anyone
 * sees them.
 */
class FixDiagnoser
{
    public const CONFIDENCE = ['low', 'medium', 'high'];

    public function __construct(private readonly AiProviders $ai) {}

    public function diagnose(AgentFix $fix): void
    {
        // The workspace's own key when it has one, else ServerOS's.
        $ai = $this->ai->forAgent($fix->workspace_id);

        $evidence = FixEvidence::gather($fix->machine, $fix->service_key);
        $fix->forceFill(['evidence' => $evidence, 'model' => $ai['model']])->save();

        [$answer, $model] = $this->ai->json($ai, self::instructions(), self::question($fix, $evidence));
        $fix->forceFill(['model' => mb_substr($model, 0, 80)]);

        $steps = FixActions::clean($answer['steps'] ?? [], $fix->machine);
        $confidence = in_array($answer['confidence'] ?? null, self::CONFIDENCE, true) ? $answer['confidence'] : 'low';

        $fix->forceFill([
            'title' => mb_substr(trim((string) ($answer['title'] ?? '')) ?: 'What the agent found', 0, 160),
            'cause' => mb_substr(trim((string) ($answer['cause'] ?? '')), 0, 600) ?: null,
            'explanation' => mb_substr(trim((string) ($answer['explanation'] ?? '')), 0, 4000) ?: null,
            'confidence' => $confidence,
            'actions' => $steps,
            'state' => $steps === [] ? 'nothing_to_do' : 'needs_approval',
            'finished_at' => $steps === [] ? now() : null,
        ])->save();
    }

    private static function instructions(): string
    {
        $types = collect(FixActions::TYPES)->map(fn (string $what, string $type) => "- {$type}: {$what}")->implode("\n");
        $max = FixActions::MAX_STEPS;

        return <<<TXT
You are ServerOS's server agent. You look at one machine (a VPS the person owns) and work out what is wrong, from the evidence you're given: its state, its services, recent log lines and failed jobs. A person reads your answer and decides whether to approve the steps. You never run anything yourself.

Be careful and honest. Log lines carry their time; mind how old they are compared with now. Base every claim on the evidence and quote the lines that show it. If the evidence doesn't show the cause, say so, keep confidence low and propose nothing rather than guess. Prefer the smallest step that fixes it. Don't propose a step that loses data. A restart that hides a recurring problem should say so.

You may only propose these steps, on services from the evidence whose managed is true (commands only in containers):
{$types}

Answer with one JSON object and nothing else:
{
  "title": "short, plain: what's wrong, under 70 characters",
  "cause": "one or two sentences: the most likely cause",
  "explanation": "plain words for the person deciding, in markdown: what you saw (quote log lines in backticks), why you think it's the cause, what the steps do and what to watch afterwards. Under 180 words.",
  "confidence": "low" | "medium" | "high",
  "steps": [ { "type": "restart", "service": "<exact service key>", "command": "only for type command", "why": "one sentence" } ]
}
At most {$max} steps. An empty list when nothing should be done, or when it needs a person (a code change, a missing secret, more disk than can be freed safely).
Write like ServerOS: plain, short sentences, no hype, no em dashes.
TXT;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function question(AgentFix $fix, array $evidence): string
    {
        $about = $fix->service_name !== null ? "The problem is about the service {$fix->service_name} ({$fix->service_key}) on {$fix->machine->name}." : "The problem is about the machine {$fix->machine->name}.";
        $note = trim((string) $fix->note) !== '' ? "\nWhat the person says: ".mb_substr(trim((string) $fix->note), 0, 1000) : '';

        return $about.$note."\nIt is now ".now()->utc()->format('M j H:i').' UTC.'."\n\nEvidence (JSON):\n".json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
