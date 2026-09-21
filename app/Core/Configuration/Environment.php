<?php

namespace Leantime\Core\Configuration;

use ArrayAccess;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Support\Str;
use Leantime\Config\Config;

/**
 * environment - class To handle environment variables
 */
class Environment extends Repository implements ArrayAccess, ConfigContract
{
    // Config Files ===============================================================================

    private ?Config $phpConfig;

    /**
     * @var array<string, string> Public environment names that differ from the
     *                            mechanically generated JULIANNA_* property name.
     */
    private const ENVIRONMENT_MAPPINGS = [
        'printLogoURL' => 'JULIANNA_PRINT_LOGO_URL',
        'primarycolor' => 'JULIANNA_PRIMARY_COLOR',
        'secondarycolor' => 'JULIANNA_SECONDARY_COLOR',
        'sessionPassword' => 'JULIANNA_APP_KEY',
        'email' => 'JULIANNA_EMAIL_RETURN',
        'useSMTP' => 'JULIANNA_EMAIL_USE_SMTP',
        'smtpHosts' => 'JULIANNA_EMAIL_SMTP_HOSTS',
        'smtpAuth' => 'JULIANNA_EMAIL_SMTP_AUTH',
        'smtpUsername' => 'JULIANNA_EMAIL_SMTP_USERNAME',
        'smtpPassword' => 'JULIANNA_EMAIL_SMTP_PASSWORD',
        'smtpAutoTLS' => 'JULIANNA_EMAIL_SMTP_AUTO_TLS',
        'smtpSecure' => 'JULIANNA_EMAIL_SMTP_SECURE',
        'smtpPort' => 'JULIANNA_EMAIL_SMTP_PORT',
        'smtpSSLNoverify' => 'JULIANNA_EMAIL_SMTP_SSLNOVERIFY',
        'useLdap' => 'JULIANNA_LDAP_USE_LDAP',
        'ldapType' => 'JULIANNA_LDAP_LDAP_TYPE',
        'ldapLtGroupAssignments' => 'JULIANNA_LDAP_GROUP_ASSIGNMENT',
        'ldapDomain' => 'JULIANNA_LDAP_LDAP_DOMAIN',
        'oidcClientId' => 'JULIANNA_OIDC_CLIENT_ID',
        'oidcClientSecret' => 'JULIANNA_OIDC_CLIENT_SECRET',
        'oidcAutoDiscoverUrl' => 'JULIANNA_OIDC_AUTO_DISCOVER',
        'oidcAuthUrl' => 'JULIANNA_OIDC_AUTH_URL_OVERRIDE',
        'oidcTokenUrl' => 'JULIANNA_OIDC_TOKEN_URL_OVERRIDE',
        'oidcJwksUrl' => 'JULIANNA_OIDC_JWKS_URL_OVERRIDE',
        'oidcUserInfoUrl' => 'JULIANNA_OIDC_USERINFO_URL_OVERRIDE',
        'oidcFieldFirstName' => 'JULIANNA_OIDC_FIELD_FIRSTNAME',
        'oidcFieldLastName' => 'JULIANNA_OIDC_FIELD_LASTNAME',
        'redisUrl' => 'JULIANNA_REDIS_URL',
    ];

    /**
     * environment constructor.
     *
     * @throws Exception
     */
    public function __construct(array $items = [])
    {
        if (! empty($items) && is_array($items)) {
            $this->items = $items;
        }

        $defaultConfiguration = new DefaultConfig;

        /* PHP */
        $this->phpConfig = null;
        if (file_exists($phpConfigFile = APP_ROOT.'/config/configuration.php')) {

            require_once $phpConfigFile;

            if (! class_exists(Config::class)) {
                throw new Exception('The PHP configuration file could not be loaded. Check its namespace and class name against config/configuration.sample.php.');
            }

            $this->phpConfig = new Config;

            $configVars = get_class_vars(Config::class);
            foreach (array_keys($configVars) as $propertyName) {
                $envVarName = self::ENVIRONMENT_MAPPINGS[$propertyName] ?? 'JULIANNA_'.Str::of($propertyName)->snake()->upper()->toString();
                putenv($envVarName.'='.$configVars[$propertyName]);
            }

        }

        $defaultConfigurationProperties = get_class_vars($defaultConfiguration::class);

        foreach (array_keys($defaultConfigurationProperties) as $propertyName) {

            $type = gettype($defaultConfigurationProperties[$propertyName]);
            $type = $type == 'NULL' ? 'string' : $type;

            $this->set($propertyName, $this->environmentHelper(
                envVar: self::ENVIRONMENT_MAPPINGS[$propertyName] ?? 'JULIANNA_'.Str::of($propertyName)->snake()->upper()->toString(),
                default: $defaultConfigurationProperties[$propertyName],
                dataType: $type,
            ));
        }

        $this->validateProductionConfiguration();

    }

    /**
     * environmentHelper - helper function to get a value from the environment
     */
    private function environmentHelper(string $envVar, mixed $default, string $dataType = 'string'): mixed
    {
        /**
         * Basically, here, we are doing the fetch order of
         * environment -> .env file -> PHP configuration -> Julianna default
         * This allows installations to use environment variables, a file, or both.
         */
        $found = $default;
        $found = $this->tryGetFromPhp($envVar, $found) ?? $found;
        $found = $this->tryGetFromEnvironment($envVar, $found) ?? $found;

        // we need to check to see if we need to convert the found data
        return match ($dataType) {
            'string' => $found,
            'boolean' => filter_var($found, FILTER_VALIDATE_BOOLEAN),
            'number' => (int) ($found),
            default => $found,
        };
    }

    private function tryGetFromPhp(string $envVar, mixed $currentValue): mixed
    {

        if ($this->phpConfig) {
            $key = array_search($envVar, self::ENVIRONMENT_MAPPINGS, true) ?: Str::of($envVar)->replace('JULIANNA_', '')->lower()->camel()->toString();

            return $this->phpConfig->$key ?? $currentValue;
        }

        return null;
    }

    /**
     * tryGetFromEnvironment - try to get a value from the environment
     */
    private function tryGetFromEnvironment(string $envVar, mixed $currentValue): mixed
    {
        return $_ENV[$envVar] ?? env($envVar) ?? $currentValue;
    }

    /**
     * Fail closed when a production deployment cannot satisfy Julianna's source
     * disclosure and cryptographic requirements.
     *
     * @throws Exception
     */
    private function validateProductionConfiguration(): void
    {
        if (strtolower((string) $this->get('env')) !== 'production') {
            return;
        }

        $errors = [];
        $sourceUrl = trim((string) $this->get('sourceUrl'));
        $sourceScheme = parse_url($sourceUrl, PHP_URL_SCHEME);

        if (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || strtolower((string) $sourceScheme) !== 'https') {
            $errors[] = 'JULIANNA_SOURCE_URL must be a public HTTPS URL for this exact source revision';
        }

        if (strlen($this->decodeAppKey((string) $this->get('sessionPassword'))) < 32) {
            $errors[] = 'JULIANNA_APP_KEY must contain at least 32 random bytes';
        }

        if ($this->get('sessionSecure') !== true) {
            $errors[] = 'JULIANNA_SESSION_SECURE must be true';
        }

        if ($errors !== []) {
            throw new Exception('Invalid Julianna production configuration: '.implode('; ', $errors).'.');
        }
    }

    private function decodeAppKey(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Dynamically access the configuration using object syntax.
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    /**
     * Dynamically set the configuration using object syntax.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Dynamically check if a configuration option is set using object syntax.
     */
    public function __isset(string $key): bool
    {
        return $this->has($key) && $this->get($key) !== null;
    }

    /**
     * Dynamically unset a configuration option using object syntax.
     */
    public function __unset(string $key): void
    {
        $this->set($key, null);
    }
}
