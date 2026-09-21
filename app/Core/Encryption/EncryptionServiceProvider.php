<?php

namespace Leantime\Core\Encryption;

use RuntimeException;

class EncryptionServiceProvider extends \Illuminate\Encryption\EncryptionServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerEncrypter();
        $this->registerSerializableClosureSecurityKey();
    }

    /**
     * Register the encrypter.
     *
     * @return void
     */
    protected function registerEncrypter()
    {

        $this->app->singleton('encrypter', function ($app) {

            $configuredKey = (string) $app['config']->sessionPassword;
            $configKey = $configuredKey;

            if (str_starts_with($configuredKey, 'base64:')) {
                $decodedKey = base64_decode(substr($configuredKey, 7), true);
                $configKey = $decodedKey === false ? '' : $decodedKey;
            }

            if (strlen($configKey) < 32) {
                throw new RuntimeException('JULIANNA_APP_KEY must contain at least 32 random bytes.');
            }

            $configKey = substr($configKey, 0, 32);
            $app['config']['app_key'] = $configKey;
            $app['config']['key'] = $configKey;

            return new \Illuminate\Encryption\Encrypter($configKey, 'AES-256-CBC');
        });
    }
}
