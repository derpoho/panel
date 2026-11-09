<?php

namespace App\Support;

use RuntimeException;

class DaemonCertificateAuthority
{
    public function certificate(): string
    {
        $path = config('daemon.ca_cert');
        $pem = is_string($path) ? @file_get_contents($path) : false;

        if (! is_string($pem) || openssl_x509_read($pem) === false) {
            throw new RuntimeException('Daemon CA is not configured. Run php artisan daemon:ca init.');
        }

        return $pem;
    }

    /** @return array{certificate: string, serial: string} */
    public function sign(string $csrPem): array
    {
        $csr = @openssl_csr_get_public_key($csrPem);
        if ($csr === false || ! str_contains($csrPem, 'BEGIN CERTIFICATE REQUEST')) {
            throw new RuntimeException('The daemon sent an invalid certificate request.');
        }

        $ca = $this->certificate();
        $keyPath = config('daemon.ca_key');
        $keyPem = is_string($keyPath) ? @file_get_contents($keyPath) : false;
        $key = is_string($keyPem) ? @openssl_pkey_get_private($keyPem) : false;
        if ($key === false) {
            throw new RuntimeException('Daemon CA private key is unavailable.');
        }

        $serial = bin2hex(random_bytes(16));
        $config = $this->extensionConfig();

        try {
            $certificate = @openssl_csr_sign($csrPem, $ca, $key, 365, [
                'config' => $config,
                'x509_extensions' => 'daemon_client',
                'digest_alg' => 'sha256',
            ], 0, $serial);
            if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
                throw new RuntimeException('Could not sign the daemon certificate.');
            }

            return ['certificate' => $pem, 'serial' => ltrim(strtolower($serial), '0') ?: '0'];
        } finally {
            @unlink($config);
        }
    }

    private function extensionConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'serveros-ca-');
        if ($path === false) {
            throw new RuntimeException('Could not prepare daemon certificate extensions.');
        }

        file_put_contents($path, "[daemon_client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\n");

        return $path;
    }
}
