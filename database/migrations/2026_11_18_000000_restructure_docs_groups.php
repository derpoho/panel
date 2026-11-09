<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The docs, regrouped to follow the panel's own sidebar: getting started,
 * machines, deploying, monitoring, backups and workflows, the workspace,
 * AI and the API, then help. Groups of one are folded into their
 * neighbours. Each section keeps its groups' order, so a group doesn't
 * sit where its oldest page happens to put it, and a line on what each
 * group covers, for the docs home's cards.
 *
 * Pages are moved by slug, edited or not; pages it doesn't know keep their
 * group and land after these.
 */
return new class extends Migration
{
    /** Section slug => group => page slugs, in order. */
    private const STRUCTURE = [
        'guides' => [
            'Getting started' => ['introduction', 'quickstart', 'installing-the-daemon', 'beta'],
            'Machines' => ['machines', 'services', 'machine-policies', 'cloning-a-service', 'clearing-machine-data', 'security', 'disconnecting'],
            'Deploying' => ['deploy-your-code', 'one-click-apps', 'deploy-on-push', 'environment-variables', 'domains-and-https', 'databases', 'updating-an-app', 'fixing-a-failed-deploy'],
            'Monitoring' => ['monitors', 'status-pages', 'status-incidents', 'logs', 'log-privacy', 'own-log-database'],
            'Backups and workflows' => ['backups', 'workflows'],
            'Workspace' => ['members-and-roles', 'two-factor', 'custom-domains', 'activity'],
            'AI and the API' => ['ai-apps', 'ai-apps-setup', 'ai-tool-calls', 'api'],
            'Help' => ['getting-help', 'feedback-and-roadmap'],
        ],
        'reference' => [
            'Basics' => ['glossary', 'plans-and-limits', 'when-a-plan-ends'],
            'Developers' => ['api-reference', 'mcp-tools', 'daemon-commands'],
        ],
    ];

    /** Group => what it covers. */
    private const DESCRIPTIONS = [
        'guides' => [
            'Getting started' => 'What ServerOS is, connecting your first machine and installing the daemon.',
            'Machines' => 'The servers you connect, what runs on them and the policies they follow.',
            'Deploying' => 'Ship your own code or a one-click app, with domains, HTTPS and databases.',
            'Monitoring' => 'Uptime monitors, status pages, incidents and logs.',
            'Backups and workflows' => 'Back up what matters and automate the jobs you repeat.',
            'Workspace' => 'Your team, their roles, sign-in security and custom domains.',
            'AI and the API' => 'Run ServerOS from Claude, ChatGPT and other AI apps, or from your own code.',
            'Help' => 'Where to get help, and how to tell us what to build next.',
        ],
        'reference' => [
            'Basics' => 'The words ServerOS uses, what each plan includes and what happens when one ends.',
            'Developers' => 'Every API endpoint, MCP tool and daemon command.',
        ],
    ];

    public function up(): void
    {
        Schema::table('doc_sections', function (Blueprint $table) {
            $table->json('group_order')->nullable();
            $table->json('group_descriptions')->nullable();
        });

        foreach (self::STRUCTURE as $sectionSlug => $groups) {
            $section = DB::table('doc_sections')->where('slug', $sectionSlug)->first();
            if ($section === null) {
                continue;
            }

            DB::table('doc_sections')->where('id', $section->id)->update([
                'group_order' => json_encode(array_keys($groups)),
                'group_descriptions' => json_encode(self::DESCRIPTIONS[$sectionSlug]),
            ]);

            foreach ($groups as $group => $slugs) {
                foreach ($slugs as $position => $slug) {
                    // Not touching updated_at: a page nobody edited stays one
                    // the seeder may refresh (and it seeds the same groups).
                    DB::table('doc_pages')->where('doc_section_id', $section->id)->where('slug', $slug)
                        ->update(['group' => $group, 'position' => $position]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('doc_sections', function (Blueprint $table) {
            $table->dropColumn(['group_order', 'group_descriptions']);
        });
    }
};
