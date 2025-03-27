<?php

namespace PhoenixPanel\Providers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class CustomEncryptionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if ($this->app->runningInConsole()) {
            global $argv;
            $command = $argv[1] ?? null;
        
            // Skip encrypter registration for 'key:generate' and potentially 'composer' commands
            if ($command === 'key:generate' || strpos($_SERVER['SCRIPT_FILENAME'], 'composer') !== false) {
                // Register a dummy encrypter
                $this->app->singleton('encrypter', function ($app) {
                    return new class implements EncrypterContract {
                        public function encrypt($value, $serialize = true) { return ''; }
                        public function encryptString($value) { return ''; }
                        public function decrypt($payload, $unserialize = true) { return ''; }
                        public function decryptString($payload) { return ''; }
                        public function getKey() { return Str::random(32); }
                        public function getCipher() { return 'dummy-cipher'; }
                        public function getAllKeys() { return []; }
                        public function getPreviousKeys() { return []; }
                    };
                });
                return;
            }
        }
    
        // Register the actual encrypter for normal application use
        $this->app->singleton('encrypter', function ($app) {
            $config = $app->make('config')->get('app');
            return new Encrypter($this->parseKey($config), $config['cipher']);
        });
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Parse the encryption key.
     *
     * @param  array  $config
     * @return string
     *
     * @throws \Illuminate\Encryption\MissingAppKeyException
     */
    protected function parseKey(array $config)
    {
        if ($this->app->runningInConsole()) {
            global $argv;
            if (isset($argv[1]) && $argv[1] === 'key:generate') {
                return '';
            }
        }

        if ($key = $config['key']) {
            return $key;
        }

        throw new MissingAppKeyException;
    }

    /**
     * Check if the current console command is 'key:generate'.
     *
     * @return bool
     */
    protected function isKeyGenerateCommand()
    {
        if ($this->app->has('artisan.input')) {
            $input = $this->app->make('artisan.input');
            return $input->getFirstArgument() === 'key:generate';
        }
        return false;
    }
}