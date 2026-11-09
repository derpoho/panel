<?php

namespace App\Console\Commands;

use App\Support\Edition;
use App\Support\Install\Installer;
use App\Support\Install\SetupCode;
use Illuminate\Console\Command;
use Throwable;

/**
 * The setup code a self-hosted install's web installer asks for first
 * (App\Support\Install\SetupCode), for whoever runs the server:
 * serveros-panel setup-code runs this in the Docker install. Once the
 * install is set up there's none.
 */
class ShowInstallCode extends Command
{
    protected $signature = 'install:code
        {--new : Make a new code (anyone who entered the old one has to enter this one)}
        {--plain : Only the code, or nothing once the install is set up (for scripts)}
        {--banner : As the web container prints it as it starts, with where to open the installer}';

    protected $description = 'Show the setup code that opens a self-hosted install’s web installer';

    public function handle(Installer $installer, SetupCode $code): int
    {
        if (! Edition::selfHosted()) {
            $this->error('Only a self-hosted install has a setup code.');

            return self::FAILURE;
        }

        if ($installer->isSetUp()) {
            if (! $this->option('plain') && ! $this->option('banner')) {
                $this->info('This install is set up already, so there’s no setup code.');
            }

            return self::SUCCESS;
        }

        try {
            // The code is kept encrypted with APP_KEY: one first, if there's none.
            $installer->ensureAppKey();
            $value = $this->option('new') ? $code->regenerate() : $code->current();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('plain')) {
            $this->line($value);

            return self::SUCCESS;
        }

        $url = rtrim((string) config('app.url'), '/').'/install';
        if ($this->option('banner')) {
            $this->line('');
            $this->line('  ServerOS isn’t set up yet. Open '.$url);
            $this->line('  and enter this setup code: '.$value);
            $this->line('  (see it again with: '.(Installer::docker() ? 'serveros-panel setup-code' : 'php artisan install:code').')');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('Setup code: <options=bold>'.$value.'</>');
        $this->line('Enter it at '.$url.' to set up this install.');

        return self::SUCCESS;
    }
}
