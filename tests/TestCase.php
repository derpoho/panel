<?php

namespace Tests;

use App\Support\Audit\AuditCoverage;
use App\Support\Auth\MailExchangers;
use App\Support\Auth\TxtRecords;
use App\Support\Logs\LogStore;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Log stores resolved by an earlier test don't carry over.
        LogStore::flush();

        // A self-hosted install's setup code and set-up marker live in a
        // folder of the test's own, never the checkout's storage.
        $install = sys_get_temp_dir().'/serveros-install-'.getmypid().'-'.bin2hex(random_bytes(6));
        config(['serveros.install.path' => $install]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($install));

        // No DNS from tests: the throwaway-inbox check sees no mail servers
        // unless a test says otherwise.
        $this->app->instance(MailExchangers::class, new class extends MailExchangers
        {
            /** @var array<string, list<string>> */
            public array $hosts = [];

            public function for(string $domain): array
            {
                return $this->hosts[$domain] ?? [];
            }
        });

        // Nor for TXT records: verifying a domain finds what a test puts in
        // `records`, by hostname; a hostname in `failing` doesn't answer.
        $this->app->instance(TxtRecords::class, new class extends TxtRecords
        {
            /** @var array<string, list<string>> */
            public array $records = [];

            /** @var list<string> */
            public array $failing = [];

            public function for(string $host): ?array
            {
                return in_array($host, $this->failing, true) ? null : ($this->records[$host] ?? []);
            }
        });

        // An #[Audited] route that changed something must have recorded it:
        // failing here means a write slipped past the log.
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if (($problem = AuditCoverage::missedRecording($event->request, $event->response)) !== null) {
                throw new RuntimeException($problem);
            }
            // Likewise an MCP tool that can change things.
            if (($missed = AuditCoverage::missedToolRecordings($event->request)) !== []) {
                throw new RuntimeException(implode(' ', $missed));
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
