<?php

namespace App\Http\Controllers\Api;

use App\Enums\MachineEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Workspace;
use App\Support\DaemonCertificateAuthority;
use App\Support\DaemonFacts;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use App\Support\TeamPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DaemonEnrolController extends Controller
{
    public function __invoke(Request $request, DaemonCertificateAuthority $ca): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'csr' => ['required', 'string', 'max:8192'],
            'daemon_version' => ['required', 'string', 'max:80'],
            'protocol_majors' => ['required', 'array'],
            'protocol_majors.*' => ['integer'],
            'facts' => ['required', 'array'],
            'facts.hostname' => ['required', 'string', 'max:255'],
            'facts.os' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.os_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.kernel' => ['sometimes', 'nullable', 'string', 'max:200'],
            'facts.arch' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.libc' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.cpu_model' => ['sometimes', 'nullable', 'string', 'max:200'],
            'facts.cpu_cores' => ['sometimes', 'integer', 'min:0'],
            'facts.memory_bytes' => ['sometimes', 'integer', 'min:0'],
            'facts.timezone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.boot_ts' => ['sometimes', 'integer'],
            'facts.init_system' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.docker_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.disks' => ['sometimes', 'array', 'max:128'],
            'facts.disks.*.mount' => ['required', 'string', 'max:255'],
            'facts.disks.*.device' => ['required', 'string', 'max:255'],
            'facts.disks.*.fs_type' => ['required', 'string', 'max:100'],
            'facts.disks.*.total_bytes' => ['required', 'integer', 'min:0'],
            'facts.interfaces' => ['sometimes', 'array', 'max:128'],
            'facts.interfaces.*.name' => ['required', 'string', 'max:100'],
            'facts.interfaces.*.addresses' => ['required', 'array', 'max:32'],
            'facts.interfaces.*.addresses.*' => ['string', 'max:100'],
            'facts.interfaces.*.mac' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.hosting' => ['sometimes', 'array'],
            'facts.hosting.provider' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.hosting.region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.hosting.zone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.hosting.instance_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'facts.hosting.source' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if (! in_array(1, $data['protocol_majors'], true)) {
            return response()->json(['error' => 'This daemon protocol is unsupported. Update the daemon.', 'code' => 'protocol_unsupported'], 422);
        }

        return DB::transaction(function () use ($request, $data, $ca): JsonResponse {
            $enrolment = DaemonEnrolment::query()->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if ($enrolment === null) {
                return response()->json(['error' => 'Token not recognised.', 'code' => 'token_invalid'], 404);
            }
            if ($enrolment->used_at !== null) {
                return response()->json(['error' => 'Token already used.', 'code' => 'token_used'], 409);
            }
            if ($enrolment->expires_at->isPast()) {
                return response()->json(['error' => 'Token expired.', 'code' => 'token_expired'], 410);
            }

            $workspace = Workspace::query()->lockForUpdate()->findOrFail($enrolment->workspace_id);
            if (! $workspace->canAddMachine()) {
                return response()->json(['error' => 'The workspace machine limit was reached.', 'code' => 'machine_limit'], 403);
            }

            try {
                $issued = $ca->sign($data['csr']);
            } catch (\RuntimeException $e) {
                report($e);

                return response()->json(['error' => 'The certificate request could not be accepted.', 'code' => 'certificate_unavailable'], 503);
            }

            $uid = (string) Str::uuid();
            $name = $data['facts']['hostname'];
            $machine = $workspace->machines()->create([
                'name' => $name,
                'ip_address' => DaemonFacts::address($data['facts'], $request->ip()) ?? '0.0.0.0',
                'provider' => DaemonFacts::provider($data['facts']) ?? 'Other',
                'environment' => MachineEnvironment::Production,
                'services_count' => 0,
            ]);
            $machine->forceFill([
                'uid' => $uid,
                'certificate_serial' => $issued['serial'],
                'daemon_version' => $data['daemon_version'],
                'facts' => $data['facts'],
                // The policy and group the join command chose, if they're
                // still there.
                'machine_policy_id' => $enrolment->machine_policy_id !== null && $workspace->machinePolicies()->whereKey($enrolment->machine_policy_id)->exists() ? $enrolment->machine_policy_id : null,
                'machine_group_id' => $enrolment->machine_group_id !== null && $workspace->machineGroups()->whereKey($enrolment->machine_group_id)->exists() ? $enrolment->machine_group_id : null,
            ]);
            // Its details from the join command; detection fills in the rest.
            $machine->forceFill(['region' => MachineMetadata::detectedRegion($machine, $data['facts'])]);
            MachineMetadata::apply($machine, $enrolment->metadata ?? []);
            $machine->forceFill(['log_ip_masking' => MachinePolicies::for($machine)->log_ip_masking])->save();
            $enrolment->update(['used_at' => now(), 'machine_id' => $machine->id]);
            // Credited to whoever made the join command (ServerOS Support
            // if staff did while viewing as them, even once that account is
            // gone), if anyone is still around to credit.
            if ($enrolment->user !== null || $enrolment->impersonation_session_id !== null) {
                Activity::record($workspace, 'machine.connect', $name, $machine, $enrolment->user, viewAs: $enrolment->impersonation_session_id);
            } else {
                Activity::system($workspace, 'machine.connect', $name, $machine);
            }
            TeamPush::send('Machine connected', "A machine connected on {$machine->provider}", TeamPush::admin('admin.machines'), 'computer');

            return response()->json([
                'machine_id' => $uid,
                'certificate' => $issued['certificate'],
                'ca' => $ca->certificate(),
                'panel_host' => config('daemon.gateway_host'),
                'label' => $name,
                // What its policy says, written into the daemon's config
                // before it first starts: nothing is scanned or sent that
                // the policy says no to.
                'settings' => MachinePolicies::enrolSettings($machine),
            ], 201)->header('Cache-Control', 'no-store');
        });
    }
}
