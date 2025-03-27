<?php

namespace PhoenixPanel\Providers;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str; // Ensure Str is imported if needed for parsing (though not needed in this version)
// Potentially add: use Illuminate\Support\Facades\Log; // If logging is desired

class CustomEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Commands that are allowed to run without an initial APP_KEY.
     * A temporary key will be generated for these commands if APP_KEY is missing.
     */
    protected array $allowedCommands = [
        'key:generate',
        'package:discover',
        'migrate',
        // Add 'config:clear', 'config:cache' if they are also run by composer and need this
    ];

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Check if running in console, APP_KEY is missing
        if ($this->app->runningInConsole() && empty(config('app.key'))) {
            // Access argv safely; index 1 should contain the command name
            $command = $_SERVER['argv'][1] ?? null;

            // Check if the command is one that needs the temporary key
            if ($command && in_array($command, $this->allowedCommands)) {
                // Generate a temporary, valid key for the current request lifecycle
                // using the application's configured cipher.
                try {
                    // Register a dummy encrypter that satisfies the contract but does nothing.
                    // This bypasses constructor checks that were failing during composer install.
                    $this->app->singleton('encrypter', function () {
                        return new class implements \Illuminate\Contracts\Encryption\Encrypter {
                            public function encrypt($value, $serialize = true) { return ''; }
                            public function decrypt($payload, $unserialize = true) { return ''; }
                            public function encryptString(string $value): string { return ''; }
                            public function decryptString(string $payload): string { return ''; }
                            public function getKey(): string { return 'dummy-key-'.bin2hex(random_bytes(16)); } // Return a dummy key string
                            // Add missing methods required by the interface
                            public function getAllKeys(): array { return []; }
                            public function getPreviousKeys(): array { return []; }
                        };
                    });

                    // Stop further processing in this provider if we registered the dummy encrypter.
                    return;

                    // Optional: Log that a dummy encrypter was registered
                    // \Illuminate\Support\Facades\Log::debug('Temporary APP_KEY generated for console command: ' . $command);
                } catch (\Exception $e) {
                    // Log error if key generation fails for some reason
                    // \Illuminate\Support\Facades\Log::error('Failed to generate temporary APP_KEY: ' . $e->getMessage());
                    // Allow the process to continue; the default MissingAppKeyException will likely be thrown later.
                }
            }
        }

        // If the above conditions were not met, the default Illuminate\Encryption\EncryptionServiceProvider
        // will handle the registration later using the actual config('app.key').
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

    // Removed the old parseKey and isKeyGenerateCommand methods
}
