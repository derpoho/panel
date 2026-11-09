<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Models\StatusSubscriber;
use App\Models\WorkspaceStatusPage;
use App\Support\StatusPages\FleetStatusReport;
use App\Support\StatusPages\StatusReport;
use App\Support\StatusPages\StatusSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Email updates and the feed of a public status page: subscribing,
 * confirming from the email, unsubscribing (opening the link asks first,
 * so a mail scanner can't unsubscribe anyone; a POST, the button or a mail
 * client's one-click unsubscribe, does it), and an Atom feed of incidents.
 */
class StatusSubscriptionController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $page = $this->page($slug);
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);

        try {
            StatusSubscriptions::subscribe($page, $data['email']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // The same answer whether or not they'd subscribed before.
        return response()->json(['message' => 'Check your inbox for a link to confirm.'], 202);
    }

    public function confirm(string $slug, string $token): Response
    {
        $page = $this->page($slug);
        $subscriber = $this->subscriber($page, $token);
        $subscriber->confirmed_at ??= now();
        $subscriber->save();

        return $this->view($page, 'You’re subscribed', 'We’ll email you when something goes wrong on '.StatusSubscriptions::title($page).' and when it’s fixed. Every email has a link to stop them.');
    }

    public function unsubscribeShow(string $slug, string $token): Response
    {
        $page = $this->page($slug);
        $subscriber = StatusSubscriber::query()->where('token', $token)->first();
        if ($subscriber === null) {
            return $this->view($page, 'You’re unsubscribed', 'You won’t get any more emails from '.StatusSubscriptions::title($page).'.');
        }

        return $this->view($page, 'Stop emails from '.StatusSubscriptions::title($page).'?', 'You won’t hear about incidents on this status page any more.', route('status.unsubscribe.store', ['slug' => $slug, 'token' => $token]));
    }

    public function unsubscribe(string $slug, string $token): Response
    {
        $page = $this->page($slug);
        StatusSubscriber::query()->where('token', $token)->delete();

        return $this->view($page, 'You’re unsubscribed', 'You won’t get any more emails from '.StatusSubscriptions::title($page).'.');
    }

    /** Written incidents and their updates, newest first, as Atom. */
    public function feed(string $slug): Response
    {
        $page = $this->page($slug);
        $report = $page instanceof WorkspaceStatusPage
            ? (new FleetStatusReport($page))->toArray()
            : (new StatusReport($page))->toArray();
        $pageUrl = route('status.show', $slug);

        return response()->view('status-feed', [
            'title' => $report['title'],
            'pageUrl' => $pageUrl,
            'feedUrl' => route('status.feed', $slug),
            'notices' => $report['notices'],
            'updated' => collect($report['notices'])->flatMap(fn ($n) => array_column($n['updates'], 'at'))->max() ?? now()->toIso8601String(),
        ], 200, [
            'Content-Type' => 'application/atom+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    /** A live, public page: private pages can't be subscribed to. */
    private function page(string $slug): StatusPage|WorkspaceStatusPage
    {
        $page = StatusPage::query()->where('slug', $slug)->with('machine.workspace')->first()
            ?? WorkspaceStatusPage::query()->where('slug', $slug)->with('workspace')->first();
        abort_if($page === null || ! $page->live() || ! $page->public, 404);

        return $page;
    }

    private function subscriber(StatusPage|WorkspaceStatusPage $page, string $token): StatusSubscriber
    {
        $subscriber = $page->subscribers()->where('token', $token)->first();
        abort_if($subscriber === null, 404);

        return $subscriber;
    }

    private function view(StatusPage|WorkspaceStatusPage $page, string $title, string $body, ?string $action = null): Response
    {
        return response()->view('status-subscription', [
            'title' => $title,
            'body' => $body,
            'action' => $action,
            'pageUrl' => route('status.show', $page->slug),
            'pageTitle' => StatusSubscriptions::title($page),
        ]);
    }
}
