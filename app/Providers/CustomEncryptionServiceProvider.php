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
                    // Hardcode the cipher to avoid config loading issues during composer scripts
                    $cipher = 'AES-256-CBC';
                    $rawKey = Encrypter::generateKey($cipher); // Generate raw binary key
                    $base64Key = 'base64:'.base64_encode($rawKey); // Create base64 version for config

                    config(['app.key' => $base64Key]); // Set base64 version in config

                    // Explicitly register encrypter with the RAW temporary key and hardcoded cipher
                    $this->app->singleton('encrypter', function ($app) use ($rawKey, $cipher) {
                        // Pass the raw binary key, not the base64 encoded string
                        return new Encrypter($rawKey, $cipher);
                    });

                    // Stop further processing in this provider if we registered the temporary encrypter.
                    // This prevents potential conflicts with the default provider.
                    return;

                    // Optional: Log that a temporary key was generated for debugging
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
