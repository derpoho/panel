<?php

namespace App\Support\Backups;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An ed25519 keypair in OpenSSH format, for senders to log in to a
 * receiving machine as serveros-backups.
 */
class ReceiverKey
{
    /**
     * @return array{private: string, public: string}
     */
    public function generate(): array
    {
        $dir = storage_path('app/private/tmp-keys/'.Str::random(16));
        File::ensureDirectoryExists($dir, 0700);

        try {
            $result = Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'serveros-backups', '-f', "{$dir}/key"]);
            if ($result->failed()) {
                throw new RuntimeException('Could not make a key for the receiving machine.');
            }

            return [
                'private' => File::get("{$dir}/key"),
                'public' => trim(File::get("{$dir}/key.pub")),
            ];
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
