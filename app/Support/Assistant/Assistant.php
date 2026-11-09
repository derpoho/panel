<?php

namespace App\Support\Assistant;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Docs\DocsSearch;
use Illuminate\Support\Str;

/**
 * Builds what the panel's assistant is told before each answer: how to
 * behave, the docs that match the question, and a little about the
 * workspace so answers can use real names. It answers questions only; it
 * can't change anything.
 */
class Assistant
{
    /**
     * Docs text sent with one question, at most. Groq's free tier allows
     * 8,000 tokens a minute, so each question has to stay small.
     */
    private const DOCS_CHARS = 7_000;

    /** Earlier messages sent along, and how long each can be. */
    private const HISTORY = 6;

    private const HISTORY_CHARS = 1_200;

    public function __construct(private DocsSearch $docs) {}

    /**
     * For the panel: knows the workspace and who's asking.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public function messages(Workspace $workspace, User $user, array $messages, ?string $path): array
    {
        $machines = $workspace->machines()->orderBy('name')->limit(25)->get(['name', 'environment', 'services_count'])
            ->map(fn ($m) => "- {$m->name} ({$m->environment->value}, {$m->services_count} services)")
            ->implode("\n") ?: '- none yet';

        $plan = $workspace->effectivePlan()?->label() ?? 'no plan';
        $firstName = Str::before((string) $user->name, ' ');

        $about = <<<ABOUT
About the person asking:
- Name: {$firstName}
- Workspace: {$workspace->name} on the {$plan} plan
- They're looking at: {$path}
- Machines in this workspace:
{$machines}
ABOUT;

        return $this->prompt('You are the assistant inside the ServerOS panel.', $about, $messages);
    }

    /**
     * For the docs site: anyone reading, signed in or not, so nothing
     * about a workspace.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public function forDocs(array $messages, ?string $path): array
    {
        $about = <<<ABOUT
About the person asking:
- They're reading the ServerOS docs, at: {$path}
- They may not have an account yet. If they're asking whether ServerOS can do something, answer plainly from the docs.
ABOUT;

        return $this->prompt('You are the assistant on the ServerOS docs site.', $about, $messages);
    }

    /**
     * The latest question in full; earlier turns trimmed to keep it small.
     * For a package's own assistant prompt, too.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    public function recent(array $messages): array
    {
        $recent = array_slice($messages, -self::HISTORY);
        $last = array_key_last($recent);
        foreach ($recent as $i => $message) {
            if ($i !== $last) {
                $recent[$i]['content'] = Str::limit($message['content'], self::HISTORY_CHARS);
            }
        }

        return $recent;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function prompt(string $intro, string $about, array $messages): array
    {
        $question = collect($messages)->where('role', 'user')->take(-2)->pluck('content')->implode("\n");

        return [
            ['role' => 'system', 'content' => $this->instructions($intro, $about, $question)],
            ...$this->recent($messages),
        ];
    }

    private function instructions(string $intro, string $about, string $question): string
    {
        $matches = $this->docs->search($question, 3);
        $budget = self::DOCS_CHARS;
        $excerpts = $matches->map(function ($page) use (&$budget) {
            $body = Str::limit($page->body, max(0, $budget), "\n[…]");
            $budget -= strlen($body);

            return "### {$page->title}\nURL: ".DocsSearch::url($page)."\n\n{$body}";
        })->filter()->implode("\n\n---\n\n");

        $index = $this->docs->pages()
            ->map(fn ($page) => "- [{$page->title}](".DocsSearch::url($page).')')
            ->implode("\n");
        $support = (string) config('mail.support.address');

        return <<<PROMPT
{$intro} ServerOS is one panel for the Linux servers people already rent: it connects to them with a small daemon, finds what's running, and handles deploys, domains, backups, monitors, logs and alerts.

What you do:
- Answer questions about using ServerOS, and explain how to do things in the panel.
- You can't take actions, see live data, or change anything. If asked to do something, explain the steps so the person can do it themselves.

How to answer:
- Base answers on the docs below and the map of the panel. If they don't cover it, say you're not sure and suggest emailing {$support}. Never invent features, buttons, tabs, log messages, settings or prices, and don't guess where something is.
- For anything with more than one step, use a short numbered list. Name buttons, tabs and pages exactly as written here, in bold. Only include steps that matter; fewer is better.
- Link the docs pages you used, with Markdown links using the docs URLs given. Those are the only links you may write. Never link to pages in the panel or anywhere else; name panel pages and buttons in bold instead.
- Use code blocks for commands people run on their server.
- Keep it short: aim for under 150 words unless they ask for more detail. Plain words. No em dashes, plain hyphens only. No filler like "Great question". Write like a helpful colleague.
- General Linux, Docker and DNS questions are fine to answer when they relate to running things on a server.

Where things are in the panel:
- Sidebar: Home, Machines, Services, Workflows, Backups, Monitors, Logs, Members, Activity, Billing, Settings.
- Machines page: the list of machines, and the **Connect machine** button.
- Deploy logs are on the machine's page, **Deployments** tab. Not on the service page.
- A machine's page: tabs **Services**, **Ports**, **Specs**, **Deployments** (every deploy with its log). Buttons **Maintenance**, **Settings**, **Scan**, **Refresh**, **Deploy service**.
- A service's page: tabs **Overview**, **Logs**, **Console**, **Details**. Buttons **Restart** and **Stop**, and **Remove** in the menu. Apps ServerOS built from code also have **Code** (with **Upload new version**) and **Domain** (with **Change domain**) boxes on Overview.
- Settings: tabs **General**, **Members**, **Profile**, **Security**.
- Environment variables are set in the deploy form (**Deploy service** on a machine). There's no other place to edit them yet: to change them, deploy again with the new values.
- Databases and one-click apps are deployed from **Deploy service** on a machine page.

{$about}

All docs pages:
{$index}

Docs that match the question:

{$excerpts}
PROMPT;
    }
}
