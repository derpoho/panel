<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        // Signs Resend's webhooks to /webhooks/resend (whsec_…), from the
        // webhook's page in Resend. Without it, every delivery is refused.
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    // The panel's assistant: answers questions from the docs.
    // The Discord channel changelog entries are posted to, as a webhook URL.
    'discord' => [
        'changelog_webhook' => env('DISCORD_CHANGELOG_WEBHOOK'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL') ?: 'openai/gpt-oss-120b',
        // The server agent's investigations run on this queue.
        'agent_queue' => env('AGENT_QUEUE') ?: 'default',
        // docs:translate: its own key if set, so translating never uses up
        // the assistants' daily allowance, and a model good at languages.
        'translate_key' => env('GROQ_TRANSLATE_KEY') ?: env('GROQ_API_KEY'),
        'translate_model' => env('GROQ_TRANSLATE_MODEL') ?: 'openai/gpt-oss-120b',
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Optional: raises GitHub's API rate limit for detecting repositories.
    // The client ID and secret are for "Continue with GitHub" (an OAuth app).
    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
    ],

    // Private repositories: a GitHub App people install on their account
    // or organisation. Its setup URL is {APP_URL}/github/setup, with
    // "Request user authorization (OAuth) during installation" on. The
    // private key is the PEM GitHub gives, or the same base64-encoded.
    // One app can do sign-in too: put its client ID and secret in
    // GITHUB_CLIENT_ID/SECRET and these use them.
    // Cloudflare for SaaS: Business workspaces' own domains are custom
    // hostnames on our zone, pointed (CNAME) at cname_target.
    // Cloudflare Turnstile on sign-up. Off until both keys are set.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cname_target' => env('CLOUDFLARE_CNAME_TARGET', 'customers.'.env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))),
    ],

    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID') ?: env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET') ?: env('GITHUB_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        // Pushes to repositories the app is installed on start "Push to
        // GitHub" workflows; GitHub signs each delivery with this.
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

    // "Continue with Google": an OAuth client from Google Cloud.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    // Pushes to the team's phones (App\Support\TeamPush), through ntfy.
    // The topic is a long random name everyone subscribes to in the app;
    // the token is only needed for a topic with access control.
    'ntfy' => [
        'server' => env('NTFY_SERVER', 'https://ntfy.sh'),
        'topic' => env('NTFY_TOPIC'),
        'token' => env('NTFY_TOKEN'),
    ],

    // Seline, cookieless analytics: the project's token, in production only.
    // Unset, there's none (serveros.com's package gives serveros.com's).
    'seline' => [
        'token' => env('SELINE_TOKEN'),
    ],

    // serveros.com's payment providers and the emails its staff send have
    // their settings in the serveros.com package's config/services.php.

];
