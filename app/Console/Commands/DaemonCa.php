<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class DaemonCa extends Command
{
    protected $signature = 'daemon:ca {action : init or issue-server} {--host=} {--out=}';

    protected $description = 'Create the daemon CA or issue a gateway TLS certificate';

    public function handle(): int
    {
        try {
            match ($this->argument('action')) {
                'init' => $this->init(),
                'issue-server' => $this->issueServer(),
                default => throw new RuntimeException('Use init or issue-server.'),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function init(): void
    {
        $certPath = config('daemon.ca_cert');
        $keyPath = config('daemon.ca_key');
        if (file_exists($certPath) || file_exists($keyPath)) {
            throw new RuntimeException('CA files already exist; refusing to replace them.');
        }

        $this->ensureDirectory(dirname($keyPath));
        $this->ensureDirectory(dirname($certPath));
        $config = $this->config("[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");

        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
            $csr = openssl_csr_new(['commonName' => 'ServerOS daemon CA'], $key, ['digest_alg' => 'sha256']);
            if ($key === false || ! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('Could not generate the daemon CA key or request.');
            }
            $cert = openssl_csr_sign($csr, null, $key, 3650, ['config' => $config, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256']);
            if ($cert === false || ! openssl_pkey_export($key, $keyPem) || ! openssl_x509_export($cert, $certPem)) {
                throw new RuntimeException('Could not generate the daemon CA.');
            }
            file_put_contents($keyPath, $keyPem);
            chmod($keyPath, 0600);
            file_put_contents($certPath, $certPem);
            $this->info("Daemon CA created at {$certPath}. Keep {$keyPath} private and backed up.");
        } finally {
            @unlink($config);
        }
    }

    private function issueServer(): void
    {
        $host = $this->option('host');
        $out = $this->option('out');
        if (! is_string($host) || ! preg_match('/^[a-z0-9][a-z0-9.-]+$/i', $host) || ! is_string($out) || $out === '') {
            throw new RuntimeException('Provide --host=gateway.example.com and --out=/secure/directory.');
        }
        if (file_exists($out.'/gateway.key') || file_exists($out.'/gateway.crt')) {
            throw new RuntimeException('Gateway certificate files already exist; refusing to replace them.');
        }

        $caKeyPem = @file_get_contents(config('daemon.ca_key'));
        $caCert = file_get_contents(config('daemon.ca_cert'));
        if ($caKeyPem === false || $caCert === false) {
            throw new RuntimeException('Run daemon:ca init first.');
        }
        $caKey = openssl_pkey_get_private($caKeyPem);
        if ($caKey === false) {
            throw new RuntimeException('The daemon CA private key is invalid.');
        }

        $this->ensureDirectory($out);
        $config = $this->config("[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:{$host}\n");
        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
            $csr = openssl_csr_new(['commonName' => $host], $key, ['digest_alg' => 'sha256']);
            if ($key === false || ! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('Could not generate the gateway key or request.');
            }
            $cert = openssl_csr_sign($csr, $caCert, $caKey, 365, ['config' => $config, 'x509_extensions' => 'server', 'digest_alg' => 'sha256'], 0, bin2hex(random_bytes(16)));
            if ($cert === false || ! openssl_pkey_export($key, $keyPem) || ! openssl_x509_export($cert, $certPem)) {
                throw new RuntimeException('Could not issue the gateway certificate.');
            }
            file_put_contents($out.'/gateway.key', $keyPem);
            chmod($out.'/gateway.key', 0600);
            file_put_contents($out.'/gateway.crt', $certPem);
            file_put_contents($out.'/ca.crt', $caCert);
            $this->info("Gateway certificate written to {$out}.");
        } finally {
            @unlink($config);
        }
    }

    private function config(string $extensions): string
    {
        $path = tempnam(sys_get_temp_dir(), 'serveros-ca-');
        if ($path === false) {
            throw new RuntimeException('Could not create certificate configuration.');
        }
        file_put_contents($path, $extensions);

        return $path;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new RuntimeException("Could not create {$path}.");
        }
    }
}
