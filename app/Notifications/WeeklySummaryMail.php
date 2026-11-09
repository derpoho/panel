<?php

namespace App\Notifications;

use App\Support\Mail\BrandMail;
use App\Support\Mail\Unsubscribes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklySummaryMail extends Notification
{
    use Queueable;

    /**
     * @param  array{headline: string, sections: list<array{title: string, lines: list<string>}>}  $summary
     */
    public function __construct(
        public string $workspaceName,
        public array $summary,
        public string $url,
        public ?int $workspaceId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->workspaceName} this week: {$this->summary['headline']}")
            ->greeting($this->summary['headline']);

        foreach ($this->summary['sections'] as $section) {
            $mail->line("**{$section['title']}**");
            foreach ($section['lines'] as $line) {
                $mail->line('- '.$line);
            }
        }

        $mail->action('Open '.$this->workspaceName, $this->url)
            ->salutation('You get this on Mondays. Turn it off under Settings › Profile.');

        $mail = BrandMail::apply($mail, [
            'image' => 'weekly',
            'heading' => $this->summary['headline'],
            'paragraphs' => [$this->workspaceName.', '.self::week().'. Here’s how the week went.'],
            'sections' => array_map(fn (array $section) => [
                'title' => $section['title'],
                'rows' => array_map(fn (string $line) => self::row($section['title'], $line), $section['lines']),
            ], $this->summary['sections']),
            'button' => ['label' => 'Open '.$this->workspaceName, 'url' => $this->url],
            'note' => 'You get this on Mondays. Turn it off under Settings › Profile.',
        ]);

        $email = $notifiable->email ?? null;

        return $this->workspaceId !== null && is_string($email)
            ? Unsubscribes::attach($mail, Unsubscribes::url($email, 'summary', $this->workspaceId), 'Stop the weekly summary')
            : $mail;
    }

    /** The last seven days: "4 to 10 October", or "28 September to 4 October". */
    private static function week(): string
    {
        [$from, $to] = [now()->subDays(7), now()->subDay()];

        return ($from->month === $to->month ? $from->format('j') : $from->format('j F')).' to '.$to->format('j F');
    }

    /**
     * One line of the summary as a table row: uptime as name and figure,
     * the rest as a line with a dot that says whether it's fine.
     *
     * @return array{name: string, value?: string, dot: 'good'|'warn'|'neutral', tone?: 'good'|'warn'|'mono'}
     */
    private static function row(string $section, string $line): array
    {
        if ($section === 'Uptime' && ($at = strrpos($line, ': ')) !== false) {
            $figure = substr($line, $at + 2);
            $state = match (true) {
                str_contains($figure, 'down') => 'warn',
                str_contains($figure, 'no checks') => 'neutral',
                default => 'good',
            };

            return ['name' => substr($line, 0, $at), 'value' => $figure, 'dot' => $state, 'tone' => $state === 'neutral' ? 'mono' : $state];
        }

        return ['name' => $line, 'dot' => match (true) {
            $section === 'Needs attention' => $line === 'Nothing right now.' ? 'good' : 'warn',
            $line === 'No backups ran.' || str_contains($line, 'failed') => 'warn',
            default => 'neutral',
        }];
    }
}
