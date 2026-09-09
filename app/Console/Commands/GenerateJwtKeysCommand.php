<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class GenerateJwtKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:generate-keys
                            {--force : Overwrite existing keys}
                            {--bits=4096 : Number of bits in the generated key}
                            {--passphrase= : Optional passphrase for the private key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate RSA public and private key pair for JWT signing and verification';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        /** @var string|null $privateKeyPath */
        $privateKeyPath = config('jwt.keys.private');
        /** @var string|null $publicKeyPath */
        $publicKeyPath = config('jwt.keys.public');

        $privateKeyPath = $this->normalizePath($privateKeyPath ?? storage_path('app/keys/jwt-rsa-4096-private.pem'));
        $publicKeyPath = $this->normalizePath($publicKeyPath ?? storage_path('app/keys/jwt-rsa-4096-public.pem'));

        $force = (bool) $this->option('force');

        if ((file_exists($privateKeyPath) || file_exists($publicKeyPath)) && ! $force) {
            $this->components->warn('JWT keys already exist. Use --force to overwrite them.');

            return self::FAILURE;
        }

        $bits = (int) $this->option('bits');
        if ($bits < 2048) {
            $bits = 4096;
        }

        $passphrase = $this->option('passphrase');
        if ($passphrase === null) {
            /** @var string|null $configPassphrase */
            $configPassphrase = config('jwt.keys.passphrase');
            $passphrase = $configPassphrase;
        }

        $this->components->info("Generating {$bits}-bit RSA key pair for JWT...");

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new RuntimeException('Failed to generate OpenSSL private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        $privateKeyPem = '';
        $exportSuccess = openssl_pkey_export($res, $privateKeyPem, $passphrase, $config);
        if (! $exportSuccess) {
            throw new RuntimeException('Failed to export OpenSSL private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        $keyDetails = openssl_pkey_get_details($res);
        if ($keyDetails === false || ! isset($keyDetails['key'])) {
            throw new RuntimeException('Failed to extract OpenSSL public key details: '.(openssl_error_string() ?: 'unknown error'));
        }

        $publicKeyPem = $keyDetails['key'];

        $privateDir = dirname($privateKeyPath);
        if (! is_dir($privateDir)) {
            mkdir($privateDir, 0755, true);
        }

        $publicDir = dirname($publicKeyPath);
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        file_put_contents($privateKeyPath, $privateKeyPem);
        file_put_contents($publicKeyPath, $publicKeyPem);

        @chmod($privateKeyPath, 0600);
        @chmod($publicKeyPath, 0644);

        $this->components->info('JWT keys generated successfully:');
        $this->components->twoColumnDetail('Private Key', $privateKeyPath);
        $this->components->twoColumnDetail('Public Key', $publicKeyPath);

        return self::SUCCESS;
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
                $path = base_path($path);
            }
        }

        return $path;
    }
}
