<?php

namespace PhoenixPanel\Providers;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract; // Needed for dummy
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException; // Needed for unsupported cipher error
// Potentially add: use Illuminate\Support\Facades\Log; // If logging is desired

class CustomEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Commands allowed to run without a real APP_KEY (will use a dummy encrypter).
     */
    protected array $allowedCommands = [
        'key:generate',
        'package:discover',
        'migrate',
        // Add 'config:clear', 'config:cache' if they are also run by composer and need this
    ];

    /**
     * Parse the encryption key from the configuration.
     * Handles base64 decoding and throws MissingAppKeyException if invalid or missing.
     * Adapted from Illuminate\Encryption\EncryptionServiceProvider.
     *
     * @param  array  $config
     * @return string The raw binary key.
     *
     * @throws \Illuminate\Encryption\MissingAppKeyException
     */
    protected function parseKey(array $config): string
    {
        if (empty($key = $config['key'])) {
            // Throw standard exception if key is not set at all in config
            throw new MissingAppKeyException('No application encryption key has been specified.');
        }

        if (Str::startsWith($key, $prefix = 'base64:')) {
            $key = base64_decode(Str::after($key, $prefix));
        }

        // Check if decoding failed or resulted in an empty string
        if ($key === false || $key === '') {
             // Throw specific exception if key format is invalid after decoding
             throw new MissingAppKeyException('Invalid application encryption key format.');
        }

        return $key;
    }

    /**
     * Register the encryption service.
     * Handles console commands specifically to ensure either a real encrypter,
     * a dummy encrypter (for allowed commands), or an appropriate exception occurs.
     *
     * @return void
     */
    public function register()
    {
        // Only intervene for console commands
        if (!$this->app->runningInConsole()) {
            // For web requests, do nothing and let the default provider handle it.
            return;
        }

        $command = $_SERVER['argv'][1] ?? null;
        $isAllowed = $command && in_array($command, $this->allowedCommands);
        $config = $this->app->make('config')->get('app');

        try {
            // Try to parse the key (throws MissingAppKeyException if missing/invalid)
            $rawKey = $this->parseKey($config);

            // --- Key Exists ---
            // Proceed with validation and real encrypter registration

            $cipher = $config['cipher'];

            // Validate key length and cipher support
            if (!Encrypter::supported($rawKey, $cipher)) {
                $ciphers = implode(', ', array_keys(Encrypter::getSupportedCiphers()));
                throw new RuntimeException("Unsupported cipher or incorrect key length. Supported ciphers are: {$ciphers}.");
            }

            // Register the REAL encrypter singleton
            $this->app->singleton('encrypter', function ($app) use ($rawKey, $cipher) {
                return new Encrypter($rawKey, $cipher);
            });

            // Successfully registered real encrypter, stop processing here.
            // Log::debug('Registered real encrypter for console command: ' . $command);
            return;

        } catch (MissingAppKeyException $e) {
            // --- Key is Missing or Invalid ---
            // Check if the command is allowed to run with a dummy encrypter.

            if ($isAllowed) {
                // Register the DUMMY encrypter singleton
                $this->app->singleton('encrypter', function () {
                    return new class implements EncrypterContract {
                        public function encrypt($value, $serialize = true) { return ''; }
                        public function decrypt($payload, $unserialize = true) { return ''; }
                        public function encryptString(string $value): string { return ''; }
                        public function decryptString(string $payload): string { return ''; }
                        public function getKey(): string { return 'dummy-key-'.bin2hex(random_bytes(16)); }
                        public function getAllKeys(): array { return []; }
                        public function getPreviousKeys(): array { return []; }
                    };
                });
                // Successfully registered dummy encrypter, stop processing here.
                // Log::debug('Registered dummy encrypter for allowed console command: ' . $command);
                return;
            } else {
                // Command is not allowed and key is missing/invalid, re-throw the original exception.
                // Log::debug('Throwing MissingAppKeyException for disallowed console command: ' . $command);
                throw $e;
            }
        } catch (RuntimeException $e) {
             // Catch the RuntimeException from the Encrypter::supported check specifically
             // Log::error('Caught RuntimeException during encrypter registration: ' . $e->getMessage());
             throw $e; // Re-throw
        } catch (\Exception $e) {
            // Catch any other unexpected exceptions during the process
            // Log::error('Unexpected error in CustomEncryptionServiceProvider: ' . $e->getMessage());
            // Re-throw to ensure failure is visible
            throw $e;
        }

        // If we somehow reach here (e.g., unexpected exception caught and not re-thrown),
        // let the default provider try its luck (though it will likely fail if we did).
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // No boot logic needed for this modification
    }
}
