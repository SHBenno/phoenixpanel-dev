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
            if (isset($argv[1]) && $argv[1] === 'key:generate') {
                // Register a dummy encrypter for key:generate
                $this->app->singleton('encrypter', function ($app) {
                    return new class implements EncrypterContract {
                        public function encrypt($value, $serialize = true) { return ''; }
                        public function encryptString($value) { return ''; }
                        public function decrypt($payload, $unserialize = true) { return ''; }
                        public function decryptString($payload) { return ''; }
                        public function getKey() { return Str::random(32); } // Return a dummy key
                        public function getCipher() { return 'dummy-cipher'; }
                        public function getAllKeys() { return []; } // Implement getAllKeys
                        public function getPreviousKeys() { return []; } // Implement getPreviousKeys
                    };
                });
                return;
            }
        }
    
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