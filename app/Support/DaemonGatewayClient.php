<?php

namespace App\Support;

use App\Models\Machine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DaemonGatewayClient
{
    /**
     * Send a control message to a machine's daemon now, outside the job
     * queue: an update to install, say.
     *
     * @param  array<string, mixed>  $control
     */
    public function control(Machine $machine, array $control): void
    {
        $base = config('daemon.gateway_internal_url');
        $secret = config('daemon.gateway_secret');
        if (! is_string($base) || $base === '' || ! is_string($secret) || $secret === '' || $machine->uid === null) {
            throw new RuntimeException('Daemon controls are not configured on this panel.');
        }

        try {
            $response = Http::timeout(5)->acceptJson()->withHeaders(['X-Gateway-Secret' => $secret])
                ->post(rtrim($base, '/').'/machines/'.$machine->uid.'/control', ['control' => $control]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('The daemon gateway is unreachable.', previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException($response->status() === 404 ? 'The daemon is offline.' : 'The gateway turned it down ('.$response->status().').');
        }
    }

    /** @param array<string, mixed> $command */
    public function dispatch(Machine $machine, string $id, array $command): void
    {
        $base = config('daemon.gateway_internal_url');
        $secret = config('daemon.gateway_secret');
        if (! config('daemon.commands_enabled') || ! is_string($base) || $base === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('Daemon commands are not configured on this panel.');
        }
        if ($machine->uid === null) {
            throw new RuntimeException('This machine has no connected daemon.');
        }

        try {
            $client = Http::timeout(5)->acceptJson()->withHeaders(['X-Gateway-Secret' => $secret]);
            $url = rtrim($base, '/').'/machines/'.$machine->uid;
            // Existing connections may have received a read-only hello before
            // commands were enabled. Reconfigure first, on the same ordered
            // gateway queue, so the following command uses managed mode.
            $mode = $client->post($url.'/control', [
                // Only the mode: a missing field leaves the daemon's setting as
                // it is, where null would clear a pinned version.
                'control' => ['type' => 'reconfigure', 'mode' => 'managed'],
            ]);
            if (! $mode->successful()) {
                throw new RuntimeException($mode->status() === 404 ? 'The daemon is offline. Reconnect it before deploying.' : 'The gateway could not enable daemon commands.');
            }

            $response = $client->post($url.'/commands', ['id' => $id, 'command' => $command]);
            if ($response->status() !== 202) {
                throw new RuntimeException($response->status() === 404 ? 'The daemon disconnected before the job could be queued.' : 'The gateway could not queue the deploy job.');
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('The daemon gateway is unreachable.', previous: $e);
        }
    }
}
