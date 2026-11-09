<?php

namespace App\Support\Activity;

use App\Models\Activity;
use App\Support\Audit\AuditContext;
use App\Support\Monitors\MonitorAlerts;
use Illuminate\Support\Carbon;

/**
 * How an activity entry reads: the words the activity page, its CSV
 * export, the admin pages and MCP all use, so a change is described the
 * same way wherever it's seen. An action without words here reads as
 * its raw name, so new actions belong here too.
 */
final class ActivityLine
{
    /**
     * The entry as a sentence: a verb phrase after the person's name (or
     * on its own for system events), the thing it was done to, and an
     * optional detail line.
     *
     * @return array{verb: string, subject: string|null, after: string|null, detail: string|null, mono: bool, tone: string, leads: bool}
     */
    public static function describe(Activity $a, bool $details): array
    {
        $meta = $a->meta ?? [];
        $out = ['verb' => $a->action, 'subject' => $a->subject, 'after' => null, 'detail' => null, 'mono' => false, 'tone' => 'neutral', 'leads' => false];
        // In the reader's language (lang/*.json), values filled in.
        $t = fn (string $text, array $values = []): string => is_string($line = __($text, $values)) ? $line : $text;
        $set = function (string $verb, ?string $after = null, string $tone = 'neutral') use (&$out, $t): void {
            $out['verb'] = $t($verb);
            $out['after'] = $after === null ? null : $t($after);
            $out['tone'] = $tone;
        };

        match ($a->action) {
            'service.deploy' => $set(($meta['retry'] ?? false) ? 'deployed again' : 'deployed', isset($meta['upload']) ? $t('from :file', ['file' => $meta['upload']]) : null),
            'service.rollback' => $set('rolled back', trim((isset($meta['commit']) && $meta['commit'] !== '' ? $t('to :commit', ['commit' => $meta['commit']]) : '').(($meta['data'] ?? false) ? $t(', with its data') : '')) ?: null, 'warn'),
            'service.start' => $set('started'),
            'service.stop' => $set('stopped', null, 'warn'),
            'service.restart' => $set('restarted'),
            'service.reload' => $set('reloaded'),
            'service.adopt' => $set('adopted'),
            'service.repair' => $set('repaired'),
            'service.clone' => $set('cloned', isset($meta['as']) ? $t(($meta['data'] ?? false) ? 'as :name, with its data' : 'as :name', ['name' => $meta['as']]) : null),
            'service.hide' => $set('hid', 'from the panel'),
            'service.unhide' => $set('showed', 'in the panel again'),
            'service.logs_delete' => $set($a->subject ? 'deleted log lines from' : 'deleted log lines', trim(implode(', ', array_filter([
                $t((int) ($meta['count'] ?? 0) === 1 ? ':count line' : ':count lines', ['count' => number_format((int) ($meta['count'] ?? 0))]),
                isset($meta['selected']) ? $t('picked one by one') : null,
                isset($meta['service']) ? (string) $meta['service'] : null,
                match ($meta['level'] ?? null) {
                    'error' => $t('errors'), 'warn' => $t('warnings and errors'), default => null
                },
                isset($meta['range']) ? $t('the last :range', ['range' => (string) $meta['range']]) : null,
                ($meta['search'] ?? false) ? $t('matching a search') : null,
            ]))) ?: null, 'warn'),
            'service.logs_mute' => $set('stopped keeping logs from'),
            'service.logs_unmute' => $set('started keeping logs from'),
            'service.rename' => $set('renamed a service to', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'service.remove' => $set('removed', ($meta['deleted_data'] ?? false) ? 'and deleted its data' : null, 'bad'),
            'console.command' => $set('ran a command in'),
            'file.write' => $set('saved'),
            'file.delete' => $set('deleted', null, 'warn'),
            'machine.connect' => $set('connected'),
            'machine.update' => $set(match (true) {
                isset($meta['packages']) => $t($meta['packages'] === 1 ? 'installed :count update on' : 'installed :count updates on', ['count' => $meta['packages']]),
                (bool) ($meta['security_only'] ?? false) => 'installed security updates on',
                default => 'installed updates on',
            }),
            'machine.packages_add' => $set($t('installed :packages on', ['packages' => implode(', ', array_slice(array_map('strval', (array) ($meta['packages'] ?? [])), 0, 6))]), null, 'warn'),
            'machine.reboot' => $set('restarted', null, 'warn'),
            'machine.firewall' => $set($t(($meta['action'] ?? null) === 'open' ? 'opened port :port on' : 'closed port :port on', ['port' => $meta['port'] ?? '']), null, ($meta['action'] ?? null) === 'open' ? 'warn' : 'neutral'),
            'machine.remove' => $set('removed the machine', null, 'bad'),
            'machine.data_clear' => $set(match (array_keys(array_intersect_key($meta, array_flip(['logs', 'metrics', 'services'])))) {
                // Lines from services its skip rules hold back.
                ['logs'] => isset($meta['skipped_services'])
                    ? $t((int) $meta['skipped_services'] === 1 ? 'deleted logs from 1 skipped service on' : 'deleted logs from :count skipped services on', ['count' => (string) $meta['skipped_services']])
                    : 'cleared logs on',
                ['metrics'] => 'cleared usage history on',
                ['services'] => 'cleared discovered services on',
                default => 'cleared collected data on',
            }, implode(', ', array_filter([
                isset($meta['logs']) ? $t((int) $meta['logs'] === 1 ? ':count log line' : ':count log lines', ['count' => self::compact((int) $meta['logs'])]) : null,
                isset($meta['metrics']) ? $t((int) $meta['metrics'] === 1 ? ':count usage reading' : ':count usage readings', ['count' => self::compact((int) $meta['metrics'])]) : null,
                isset($meta['services']) ? $t((int) $meta['services'] === 1 ? ':count discovered service' : ':count discovered services', ['count' => self::compact((int) $meta['services'])]) : null,
            ])) ?: null, 'warn'),
            'machine.move_in' => $set('moved in', isset($meta['from']) ? $t('from :name', ['name' => $meta['from']]) : null),
            'machine.move_out' => $set('moved out', isset($meta['to']) ? $t('to :name', ['name' => $meta['to']]) : null, 'warn'),
            'machine.log_skip' => $set(
                ($meta['services'] ?? 0) + ($meta['patterns'] ?? 0) === 0 ? 'stopped skipping logs on' : 'changed which logs never leave',
                ($meta['services'] ?? 0) + ($meta['patterns'] ?? 0) === 0 ? null : $t(':services services and :patterns patterns skipped', ['services' => (string) ($meta['services'] ?? 0), 'patterns' => (string) ($meta['patterns'] ?? 0)]),
            ),
            'machine.log_ip_masking' => $set(match ($meta['to'] ?? 'off') {
                'partial' => 'started masking IP addresses (partly) in logs from',
                'hash' => 'started masking IP addresses (hashed) in logs from',
                default => 'stopped masking IP addresses in logs from',
            }),
            'machine.policy' => $set('changed the policy of', isset($meta['to']) ? $t('to :name', ['name' => $meta['to']]) : null),
            'machine.daemon_update' => $set('approved a daemon update on', isset($meta['to']) ? $t('to :name', ['name' => $meta['to']]) : null),
            'machine.daemon_updated' => $set('is on a new daemon', isset($meta['to']) ? $t(':from to :to', ['from' => $meta['from'] ?? '', 'to' => $meta['to']]) : null, 'good'),
            'daemon.update_waiting' => $set('is waiting for approval', isset($meta['machines']) ? $t((int) $meta['machines'] === 1 ? 'on 1 machine' : 'on :count machines', ['count' => (string) $meta['machines']]) : null),
            'machine.metadata' => $set('changed the details of', isset($meta['fields']) ? implode(', ', array_map(fn ($f) => $t($f), (array) $meta['fields'])) : null),
            'machines.metadata' => $set('changed the details of', $t((int) ($meta['machines'] ?? 0) === 1 ? '1 machine' : ':count machines', ['count' => (string) ($meta['machines'] ?? 0)])),
            'machine.rename' => $set('renamed', isset($meta['from']) ? $t('from :name', ['name' => $meta['from']]) : null),
            'machine.environment' => $set('moved', isset($meta['to']) ? $t('to :name', ['name' => $t(ucfirst($meta['to']))]) : null),
            'machine.offline' => $set('stopped reporting', null, 'bad'),
            'machine.online' => $set('is reporting again', isset($meta['seconds']) ? $t('after :duration', ['duration' => MonitorAlerts::duration((int) $meta['seconds'])]) : null, 'good'),
            'machine.disk_full' => $set('is running out of disk', isset($meta['percent']) ? $t(':percent% full', ['percent' => $meta['percent']]) : null, 'warn'),
            'service.down' => $set('stopped unexpectedly', null, 'bad'),
            'service.up' => $set('is running again', isset($meta['seconds']) ? $t('after :duration', ['duration' => MonitorAlerts::duration((int) $meta['seconds'])]) : null, 'good'),
            'service.crash_loop' => $set('keeps restarting', isset($meta['restarts']) ? $t(':count restarts', ['count' => $meta['restarts']]) : null, 'bad'),
            'monitor.add' => $set('added the monitor'),
            'monitor.update' => $set('changed the monitor'),
            'monitor.pause' => $set('paused the monitor'),
            'status_page.fleet' => $set(($meta['enabled'] ?? false) ? 'updated the fleet status page' : 'turned off the fleet status page'),
            'issues.dismiss' => $set('marked as read', 'in Needs attention'),
            'monitor.resume' => $set('resumed the monitor'),
            'monitor.remove' => $set('deleted the monitor', null, 'bad'),
            'monitor.down' => $set('went down', null, 'bad'),
            'monitor.up' => $set('is back up', isset($meta['seconds']) ? $t('after :duration', ['duration' => MonitorAlerts::duration((int) $meta['seconds'])]) : null, 'good'),
            'backup.run' => $set('backed up'),
            'backup.failed' => $set('couldn’t be backed up', null, 'bad'),
            'backup.restore' => ($meta['undo'] ?? false)
                ? $set('undid the restore of', null, 'warn')
                : $set('restored', isset($meta['from']) ? $t('from the backup of :name', ['name' => $meta['from']]) : null, 'warn'),
            'backup.restore_failed' => $set('couldn’t be restored', null, 'bad'),
            'backups.schedule' => $set('set backups for', match ($meta['schedule'] ?? null) {
                'off' => 'to manual only',
                null => null,
                default => $t(isset($meta['destination']) ? 'to :schedule to :destination' : 'to :schedule', ['schedule' => $t($meta['schedule']), 'destination' => $meta['destination'] ?? '']),
            }),
            'backup_destination.add' => $set('added the backup destination'),
            'backup_destination.remove' => $set('removed the backup destination', null, 'bad'),
            'status_page.publish' => $set('published the status page'),
            'status_page.unpublish' => $set('took down the status page', null, 'warn'),
            'status_page.update' => $set('changed the status page'),
            'status_page.incident_open' => $set('posted an incident:', null, 'warn'),
            'status_page.incident_update' => $set('posted an update on', null),
            'status_page.incident_resolve' => $set('resolved the incident', null),
            'status_page.incident_delete' => $set('deleted the incident', null, 'warn'),
            'plan.set_by_serveros' => $set($a->subject ? 'moved the workspace to' : 'took the workspace off its plan', $a->subject ? trim(($meta['interval'] ?? '').(isset($meta['until']) ? $t(', until :until', ['until' => $meta['until']]) : '')) ?: null : null),
            'plan.manual_ended' => $set('ended', 'when its time ran out', 'warn'),
            'plan.payment_failed' => $set('couldn’t renew', isset($meta['until']) ? $t('because the payment didn’t go through; it ends on :until unless it’s paid', ['until' => $meta['until']]) : 'because the payment didn’t go through', 'warn'),
            'plan.ended' => $set('ended', match ($meta['reason'] ?? null) {
                'unpaid' => 'because the renewal was never paid',
                'cancelled', 'expired' => 'because the subscription was cancelled',
                default => null,
            }, 'bad'),
            'plan.trial_extended' => $set('extended the trial', isset($meta['days'], $meta['until']) ? $t('by :days days, to :until', ['days' => $meta['days'], 'until' => $meta['until']]) : null, 'good'),
            'member.invite' => $set('invited', isset($meta['role']) ? $t('as :role', ['role' => $t($meta['role'])]) : null),
            'member.uninvite' => $set('cancelled the invite for'),
            'member.role' => ($meta['via'] ?? null) === 'sso_sync'
                ? $set($t('got the :role role from their SSO groups', ['role' => $t((string) ($meta['role'] ?? ''))]), isset($meta['from']) ? $t('(was :role)', ['role' => $t($meta['from'])]) : null)
                : $set('gave', isset($meta['role']) ? $t('the :role role', ['role' => $t($meta['role'])]) : 'a new role'),
            'role.create' => $set('added the role'),
            'role.update' => $set('changed the role'),
            'role.delete' => $set('deleted the role', null, 'warn'),
            'security.two_factor_on' => $set('required two-factor sign-in for everyone', null, 'good'),
            'security.two_factor_off' => $set('stopped requiring two-factor sign-in', null, 'warn'),
            'activity.export' => $set('exported the activity log', ($meta['filtered'] ?? false) ? 'with filters' : null),
            'workspace.rename' => $set('renamed the workspace to', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'workspace.logo' => $set('changed the workspace logo'),
            'workspace.logo_remove' => $set('removed the workspace logo'),
            'workspace.log_storage_update' => $set(($meta['added'] ?? false) ? 'added a log database at' : 'changed the log database at', isset($meta['prune']) ? ($meta['prune'] ? 'to delete lines after the plan’s retention' : 'to keep lines until you delete them') : null),
            'workspace.log_storage_enable' => $set('started keeping logs in the database at', null, 'good'),
            'workspace.log_storage_disable' => $set('stopped keeping logs in the database at', 'so new lines go to ServerOS again', 'warn'),
            'workspace.log_storage_remove' => $set('removed the log database at', null, 'warn'),
            // A self-hosted workspace's ServerOS Business license; the subject is its customer.
            'workspace.license_add' => $set('added the ServerOS Business license for', null, 'good'),
            'workspace.license_remove' => $set('removed the ServerOS Business license for', null, 'warn'),
            'workspace.delete' => $set('deleted the workspace', null, 'bad'),
            'machine_group.create' => $set('created the machine group'),
            'machine_group.update' => match (true) {
                isset($meta['from']) => $set('renamed a machine group to', $t('(was :name)', ['name' => $meta['from']])),
                isset($meta['policy']) => $set('changed the policy of the machine group', $t('to :name', ['name' => $meta['policy']])),
                default => $set('changed which machines are in the group'),
            },
            'machine_policy.create' => $set('created the machine policy'),
            'machine_policy.update' => $set('changed the machine policy', isset($meta['machines']) ? $t((int) $meta['machines'] === 1 ? '1 machine follows it' : ':count machines follow it', ['count' => (string) $meta['machines']]) : null),
            'machine_policy.default' => $set('made the default machine policy'),
            'machine_policy.delete' => $set('deleted the machine policy', null, 'warn'),
            'machine_group.delete' => $set('deleted the machine group', null, 'warn'),
            'service_group.create' => $set('created the service group'),
            'service_group.update' => isset($meta['from'])
                ? $set('renamed a service group to', $t('(was :name)', ['name' => $meta['from']]))
                : $set('changed which services are in the group'),
            'service_group.delete' => $set('deleted the service group', null, 'warn'),
            'cloud.order' => $set('ordered the Cloud server', isset($meta['size']) ? $t('(:size)', ['size' => $meta['size']]) : null),
            'cloud.delete' => $set('deleted the Cloud server', null, 'warn'),
            'cloud.suspend' => $set('was stopped for a payment', null, 'bad'),
            'cloud.resume' => $set('was paid for and started again', null, 'good'),
            'cloud.throttle' => $set('used its transfer for the month and was slowed down', null, 'warn'),
            'cloud.end' => $set('was deleted when its cancelled subscription ran out', null, 'warn'),
            'project.create' => $set('created the project'),
            'project.delete' => $set('deleted the project', null, 'warn'),
            'project.link' => $set('connected', $t('in a project')),
            'project.resource' => $set('added'),
            'project.add' => $set('added'),
            'project.remove' => $set('took', null, 'warn'),
            'project.rename' => $set('renamed a project to', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'project.unlink' => $set('disconnected', $t('in a project'), 'warn'),
            'project.environment' => $set('made the environment', isset($meta['from']) ? $t('(a copy of :name)', ['name' => $meta['from']]) : null),
            'project.environment_update' => $set('changed the environment'),
            'project.environment_delete' => $set('deleted the environment', null, 'warn'),
            'project.refresh' => $set('copied data into', isset($meta['from']) ? $t('from :name', ['name' => $meta['from']]) : null),
            'project.promote' => $set('promoted'),
            'project.settings' => $set('changed the settings of'),
            'app.variables' => $set('changed the settings of'),
            'project.previews' => $set(($meta['enabled'] ?? false) ? 'turned on pull request previews for' : 'turned off pull request previews for'),
            'project.preview' => $set('opened a preview for pull request', isset($meta['commit']) ? $t('at :commit', ['commit' => $meta['commit']]) : null),
            'project.preview_update' => $set('updated the preview for pull request', isset($meta['commit']) ? $t('to :commit', ['commit' => $meta['commit']]) : null),
            'project.preview_closed' => $set('removed the preview for pull request', null, 'warn'),
            'project.preview_skipped' => $set('skipped a preview for pull request', $meta['reason'] ?? null, 'warn'),
            'member.join' => ($meta['via'] ?? null) === 'scim'
                ? $set('added', isset($meta['role']) ? $t('to the workspace as :role', ['role' => $t($meta['role'])]) : 'to the workspace', 'good')
                : $set('joined the workspace', match (true) {
                    ($meta['via'] ?? null) === 'google_domain' && isset($meta['role'], $meta['domain']) => $t('as :role, signed in with Google on :domain', ['role' => $t($meta['role']), 'domain' => $meta['domain']]),
                    ($meta['via'] ?? null) === 'sso' && isset($meta['role']) => $t('as :role, signed in with SSO', ['role' => $t($meta['role'])]),
                    isset($meta['role']) => $t('as :role', ['role' => $t($meta['role'])]),
                    default => null,
                }, 'good'),
            'member.request' => $set('asked to join the workspace', isset($meta['domain']) ? $t('after signing in with Google on :domain', ['domain' => $meta['domain']]) : null),
            'member.request_approve' => $set('let in', isset($meta['role']) ? $t('as :role, who asked to join with Google', ['role' => $t($meta['role'])]) : null, 'good'),
            'member.request_deny' => $set('turned down the request to join from', null, 'warn'),
            'email_domain.add' => $set('added the domain', null),
            'email_domain.verified' => $set('verified the domain', null, 'good'),
            'email_domain.unverified' => $set($a->byPerson() ? 'checked' : 'is no longer verified', $a->byPerson() ? 'and its TXT record is gone, so it’s no longer verified' : 'because its TXT record is gone', 'warn'),
            'email_domain.remove' => $set('removed the domain', null, 'warn'),
            'sign_in.domain_join' => $set(match ($meta['mode'] ?? null) {
                'auto' => 'let anyone on the workspace’s domains join',
                'request' => 'let anyone on the workspace’s domains ask to join',
                default => 'stopped people on the workspace’s domains joining without an invite',
            }, isset($meta['role']) && ($meta['mode'] ?? null) === 'auto' ? $t('as :role', ['role' => $t($meta['role'])]) : null),
            'sign_in.require_google_on' => $set('required Google sign-in on the workspace’s domains', null, 'good'),
            'sign_in.require_google_off' => $set('stopped requiring Google sign-in', null, 'warn'),
            'sso.connection_create' => $set('set up the SSO connection', match ($meta['type'] ?? null) {
                'saml' => 'over SAML', 'oidc' => 'over OpenID Connect', default => null,
            }),
            'sso.connection_update' => $set('changed the SSO connection'),
            'sso.connection_delete' => $set('removed the SSO connection', null, 'warn'),
            'sso.connection_enable' => $set('turned on SSO sign-in with', null, 'good'),
            'sso.connection_disable' => $set('turned off SSO sign-in with', null, 'warn'),
            'sso.connection_test' => ($meta['ok'] ?? false)
                ? $set('ran a test sign-in with', 'and it worked', 'good')
                : $set('ran a test sign-in with', 'and it didn’t work', 'warn'),
            'sso.require_on' => $set('required SSO sign-in for members', null, 'good'),
            'sso.require_off' => $set('stopped requiring SSO sign-in', null, 'warn'),
            'sso.role_mapping_add' => $set('mapped the SSO group', isset($meta['role']) ? $t('to the :role role', ['role' => $t($meta['role'])]) : null),
            'sso.role_mapping_update' => $set('changed the role for the SSO group', isset($meta['role']) ? $t('to :role', ['role' => $t($meta['role'])]) : null),
            'sso.role_mapping_remove' => $set('stopped mapping the SSO group', 'to a role', 'warn'),
            'sso.encryption_rotation_start' => $set('started a new encryption certificate for'),
            'sso.encryption_rotation_finish' => $set('retired the old encryption certificate of', null, 'warn'),
            'sso.identity_unlink' => $set('unlinked the SSO sign-in of', isset($meta['connection']) ? $t('from :name', ['name' => $meta['connection']]) : null, 'warn'),
            'sso.identities_reset' => $set('changed the identity provider or certificate behind', $t('so :count people with accounts of their own link again at their next sign-in', ['count' => (string) ($meta['count'] ?? 0)]), 'warn'),
            'sso.identity_link' => $set('linked their ServerOS account to', 'after signing in to it', 'good'),
            'sso.logout_by_idp' => $set('was signed out by', 'after logging out there'),
            'sso.metadata_certificates_changed' => $set('has new signing certificates', 'from its metadata'),
            'sso.metadata_refresh_failed' => $set('couldn’t have its metadata read again', 'so the saved copy is kept', 'warn'),
            'console.unlock' => $set('unlocked the console'),
            'mcp.connect' => $set('connected', 'through MCP'),
            'job.abandon' => $set($t('gave up on the stuck :kind job for', ['kind' => str_replace('_', ' ', (string) ($meta['kind'] ?? ''))]), null, 'warn'),
            'app.settings' => match ($meta['snapshot_before'] ?? null) {
                true => $set('turned on the snapshot before each deploy of'),
                false => $set('turned off the snapshot before each deploy of', null, 'warn'),
                default => $set('changed which database is snapshotted before deploys of'),
            },
            'backup_destination.update' => $set('changed the backup destination', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'backup_destination.retry' => $set('set up the backup destination again'),
            'workspace.create' => $set('created the workspace', null, 'good'),
            'workspace.trial_plan' => $set('moved the free trial to', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'workspace.card_trial' => $set('added a card to start the free trial of', isset($meta['until']) ? $t('first charged on :date', ['date' => $meta['until']]) : null, 'good'),
            'plan.started' => $set('subscribed to', self::billed($meta), 'good'),
            'plan.changed' => $set(isset($meta['from']) ? $t('moved the workspace from :from to', ['from' => $meta['from']]) : 'moved the workspace to', self::billed($meta)),
            'member.remove' => $set('removed', match (true) {
                ($meta['via'] ?? null) !== 'scim' => 'from the workspace',
                ($meta['reason'] ?? null) === 'deleted' => ($meta['signed_out'] ?? false) ? 'from the workspace, deleted in the identity provider, and signed them out' : 'from the workspace, deleted in the identity provider',
                default => ($meta['signed_out'] ?? false) ? 'from the workspace, deactivated in the identity provider, and signed them out' : 'from the workspace, deactivated in the identity provider',
            }, 'bad'),
            'scim.token_create' => $set('created the SCIM token'),
            'scim.token_revoke' => $set('revoked the SCIM token', null, 'warn'),
            'scim.default_role' => $set(isset($meta['role']) ? $t('made people provisioned with SCIM join as :role', ['role' => $t($meta['role'])]) : 'changed the role for people provisioned with SCIM'),
            'scim.user_link' => $set('started managing', 'who was already a member'),
            'scim.user_delete' => $set('stopped managing', null, 'warn'),
            'scim.email_kept' => $set('kept the sign-in email of', isset($meta['email'], $meta['sent']) ? $t('as :email; the identity provider now sends :sent', ['email' => $meta['email'], 'sent' => $meta['sent']]) : null, 'warn'),
            'scim.owner_kept' => $set('didn’t remove', 'because they own the workspace', 'warn'),
            'scim.group_create' => $set('added the group', isset($meta['members']) ? $t((int) $meta['members'] === 1 ? 'with :count member' : 'with :count members', ['count' => (int) $meta['members']]) : null),
            'scim.group_rename' => $set('renamed a group to', isset($meta['from']) ? $t('(was :name)', ['name' => $meta['from']]) : null),
            'scim.group_members' => $set('changed who’s in the group', implode(', ', array_filter([
                isset($meta['added']) ? $t(':count added', ['count' => (int) $meta['added']]) : null,
                isset($meta['removed']) ? $t(':count removed', ['count' => (int) $meta['removed']]) : null,
            ])) ?: null),
            'scim.group_delete' => $set('deleted the group', null, 'warn'),
            'member.leave' => $set('left the workspace'),
            'alerts.update' => $set('changed where alerts go'),
            'mcp.key_create' => $set('created the MCP key'),
            'api.key_create' => $set('created the API key'),
            'domain.add' => $set('connected the domain'),
            'domain.active' => $set('is live', null, 'good'),
            'domain.remove' => $set('disconnected the domain', null, 'warn'),
            'api.key_revoke' => $set('revoked the API key', null, 'warn'),
            'mcp.revoke' => match (true) {
                // The app ended it itself (OAuth revoke): the subject leads.
                (bool) ($meta['by_client'] ?? false) => $set('disconnected itself from MCP', isset($meta['person']) ? $t('(:person’s connection)', ['person' => $meta['person']]) : null, 'warn'),
                ($meta['kind'] ?? null) === 'oauth' => $set('disconnected', 'from MCP', 'warn'),
                default => $set('revoked the MCP key', null, 'warn'),
            },
            'machine.maintenance' => $set('started maintenance on', isset($meta['minutes']) ? $t(($meta['reason'] ?? null) ? 'for :duration: :reason' : 'for :duration', ['duration' => MonitorAlerts::duration((int) $meta['minutes'] * 60), 'reason' => $meta['reason'] ?? '']) : null, 'warn'),
            'machine.maintenance_end' => $set('ended maintenance on', null, 'good'),
            'machine.maintenance_scheduled' => $set('planned maintenance on', isset($meta['starts_at']) ? $t('for :when', ['when' => Carbon::parse($meta['starts_at'])->format('j M, H:i').' UTC']) : null),
            'machine.maintenance_cancelled' => $set('called off maintenance on', null, 'warn'),
            'machine.maintenance_done' => $set('finished maintenance on', $meta['outcome'] ?? null, 'good'),
            'updates.check_all' => $set('checked for updates', $t((int) ($meta['machines'] ?? 0) === 1 ? 'on :count machine' : 'on :count machines', ['count' => (int) ($meta['machines'] ?? 0)])),
            'updates.rollout' => $set(isset($meta['scheduled_for']) ? 'planned the rollout' : 'started the rollout', trim(implode(', ', array_filter([
                $t((int) ($meta['machines'] ?? 0) === 1 ? 'on :count machine' : 'on :count machines', ['count' => (int) ($meta['machines'] ?? 0)]),
                ($meta['mode'] ?? null) === 'exact' ? $t('exact versions') : null,
                isset($meta['batch']) ? $t(':count at a time', ['count' => (int) $meta['batch']]) : null,
                isset($meta['scheduled_for']) ? $t('for :when', ['when' => Carbon::parse($meta['scheduled_for'])->format('j M, H:i').' UTC']) : null,
            ]))) ?: null, 'warn'),
            'updates.rollout_cancel' => $set('cancelled the rollout', null, 'warn'),
            'updates.rollout_done' => $set('finished the rollout', $t(':done done, :failed failed, :skipped skipped', [
                'done' => (int) ($meta['done'] ?? 0), 'failed' => (int) ($meta['failed'] ?? 0), 'skipped' => (int) ($meta['skipped'] ?? 0),
            ]), ($meta['state'] ?? null) === 'failed' ? 'bad' : 'good'),
            'updates.hold' => $set('held', $t((int) ($meta['machines'] ?? 0) === 1 ? 'at their version on :count machine' : 'at their version on :count machines', ['count' => (int) ($meta['machines'] ?? 0)]), 'warn'),
            'updates.unhold' => $set('let go of', $t((int) ($meta['machines'] ?? 0) === 1 ? 'on :count machine' : 'on :count machines', ['count' => (int) ($meta['machines'] ?? 0)])),
            'monitor.mute' => $set('muted the monitor', isset($meta['minutes']) ? $t('for :duration', ['duration' => MonitorAlerts::duration((int) $meta['minutes'] * 60)]) : null, 'warn'),
            'monitor.unmute' => $set('unmuted the monitor'),
            'workflow.add' => $set('created the workflow'),
            'workflow.update' => $set('changed the workflow'),
            'workflow.enable' => $set('turned on the workflow', null, 'good'),
            'workflow.disable' => $set('turned off the workflow', null, 'warn'),
            'workflow.remove' => $set('deleted the workflow', null, 'bad'),
            'workflow.webhook' => $set('made a new webhook URL for'),
            'workflow.cancel' => $set('stopped a run of', null, 'warn'),
            'workflow.run' => ! $a->byPerson()
                ? $set('ran', match ($meta['trigger'] ?? null) {
                    'schedule' => 'on schedule', 'webhook' => 'from its webhook', null, 'manual' => null, default => $t('after :trigger', ['trigger' => str_replace(['.', '_'], ' ', $meta['trigger'])])
                })
                : $set('ran the workflow'),
            'workflow.failed' => $set('failed', null, 'bad'),
            'ai.connect' => $set('connected their own AI key:', null, 'warn'),
            'ai.update' => $set('changed their AI key for'),
            'ai.disconnect' => $set('disconnected their AI key for', null, 'warn'),
            'fix.start' => $set('asked the agent to look into'),
            'fix.approve' => $set('approved the agent’s fix', $t((int) ($meta['steps'] ?? 0) === 1 ? ':count step' : ':count steps', ['count' => (int) ($meta['steps'] ?? 0)]), 'warn'),
            'fix.decline' => $set('turned down the agent’s fix'),
            'fix.done' => $set('fixed', null, 'good'),
            'fix.failed' => $set('couldn’t fix', null, 'bad'),
            default => null,
        };

        if ($a->action === 'console.command' && $details) {
            $out['detail'] = $meta['command'] ?? null;
            $out['mono'] = true;
        } elseif (in_array($a->action, ['backup.failed', 'backup.restore_failed'], true)) {
            $out['detail'] = $meta['error'] ?? null;
        } elseif ($a->action === 'monitor.down') {
            $out['detail'] = $meta['cause'] ?? null;
        }

        // System events read "<subject> went down"; the subject leads.
        if (! $a->byPerson() && in_array($a->action, ['machine.offline', 'machine.online', 'machine.disk_full', 'monitor.down', 'monitor.up', 'backup.failed', 'backup.restore_failed', 'machine.connect', 'service.down', 'service.up', 'service.crash_loop', 'workflow.run', 'workflow.failed', 'fix.done', 'fix.failed', 'mcp.revoke', 'domain.active', 'machine.daemon_updated', 'daemon.update_waiting', 'email_domain.unverified', 'cloud.suspend', 'cloud.resume', 'cloud.end', 'cloud.throttle'], true)) {
            $out['leads'] = true;
        }

        return $out;
    }

    /** A count short enough to read at a glance: 980, 14,200, 1.2M. */
    public static function compact(int $n): string
    {
        return match (true) {
            $n >= 1_000_000_000 => rtrim(rtrim(number_format($n / 1_000_000_000, 1), '0'), '.').'B',
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M',
            default => number_format($n),
        };
    }

    /**
     * "(billed monthly)" for a plan entry, or nothing.
     *
     * @param  array<string, mixed>  $meta
     */
    private static function billed(array $meta): ?string
    {
        return match ($meta['interval'] ?? null) {
            'monthly' => '(billed monthly)',
            'annual' => '(billed yearly)',
            default => null,
        };
    }

    /**
     * Who did it, as the reader may see it: the person (as they were then,
     * if they've since left), or ServerOS Support for what staff did while
     * viewing as someone. A workspace never learns which admin that was;
     * `$staff` adds their name for our own pages. Null when it happened on
     * its own.
     *
     * @return array{name: string, email: string|null, avatar: string|null, support: bool}|null
     */
    public static function actor(Activity $a, bool $staff = false): ?array
    {
        if ($a->impersonation_session_id !== null) {
            $support = is_string($word = __(AuditContext::SUPPORT)) ? $word : AuditContext::SUPPORT;
            $admin = $staff ? $a->impersonation?->admin_name : null;

            return ['name' => $admin === null ? $support : "{$support} ({$admin})", 'email' => null, 'avatar' => null, 'support' => true];
        }

        $name = $a->user->name ?? $a->actor_name;
        // The company's identity provider, through a SCIM token: "Okta (SCIM)".
        if ($name === null && ($a->meta['via'] ?? null) === 'scim') {
            $client = $a->meta['client'] ?? null;

            return ['name' => is_string($client) && $client !== '' ? "{$client} (SCIM)" : 'SCIM', 'email' => null, 'avatar' => null, 'support' => false];
        }
        if ($name === null) {
            return null;
        }

        return ['name' => $name, 'email' => $a->user->email ?? $a->actor_email, 'avatar' => $a->user?->avatar, 'support' => false];
    }

    /**
     * The whole line as the activity page shows it: "Ada restarted umami
     * on web-1", or "web-1 stopped reporting" for things that happened on
     * their own.
     *
     * @param  array{verb: string, subject: string|null, after: string|null, leads: bool}|null  $line  from describe(), if already worked out
     */
    public static function sentence(Activity $a, ?array $line = null): string
    {
        $line ??= self::describe($a, false);

        $words = $line['leads']
            ? [$line['subject'], $line['verb']]
            : [self::actor($a)['name'] ?? 'ServerOS', $line['verb'], $line['subject']];
        $words[] = $line['after'];
        if ($a->machine !== null && $line['subject'] !== $a->machine->name) {
            $on = __('on :machine', ['machine' => $a->machine->name]);
            $words[] = is_string($on) ? $on : "on {$a->machine->name}";
        }

        return implode(' ', array_filter(array_map(fn ($w) => trim((string) $w), $words), fn (string $w) => $w !== ''));
    }
}
