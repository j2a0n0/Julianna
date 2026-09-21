<?php

namespace Leantime\Domain\Status\Controllers;

use Leantime\Core\Configuration\AppSettings;
use Leantime\Core\Configuration\Environment;
use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, unauthenticated instance status / discovery endpoint — GET /status.
 *
 * This endpoint advertises only Julianna's local password + mandatory MFA
 * identity flow. Legacy LDAP/OIDC implementations cannot be enabled here.
 *
 * SECURITY — this endpoint is UNAUTHENTICATED, so it returns ONLY the safe,
 * minimal tier: auth methods, the core version + instance name, provider labels,
 * and the min app version. It deliberately does NOT list installed plugins,
 * plugin versions, or the db version — an unauthenticated inventory of those is a
 * recon gift (CVE matching). Those stay behind auth (the authenticated
 * mobileStatus). Keep any `publicStatus` filter additions to this safe tier.
 *
 * Route 'status.index' is allow-listed public in AuthCheck.
 */
class Index extends Controller
{
    /**
     * Keys that must NEVER appear in the unauthenticated response, even if a
     * publicStatus filter (or a future edit) adds them — a recon-risk inventory.
     * Stripped after the filter runs, as defense in depth.
     */
    private const SENSITIVE_KEYS = ['plugins', 'pluginVersions', 'installedPlugins', 'dbVersion', 'db_version'];

    private Environment $config;

    private AppSettings $appSettings;

    /**
     * init - inject public product configuration.
     */
    public function init(Environment $config, AppSettings $appSettings): void
    {
        $this->config = $config;
        $this->appSettings = $appSettings;
    }

    /**
     * Return the public discovery payload for Julianna's local identity flow —
     * the safe unauthenticated tier only. Never plugin inventory, plugin
     * versions, or database version (see the class-level security note).
     */
    public function get(array $params): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = [
            'mobileAuthEnabled' => false,
            'instanceName' => (string) ($this->config->sitename ?: 'Julianna'),
            'version' => (string) ($this->config->version ?: $this->appSettings->appVersion),
            'minAppVersion' => null,
            'authMethods' => ['password'],
            'mfaRequired' => true,
            'ssoProviders' => [],
        ];

        // Defense in depth: this endpoint is unauthenticated, so strip any
        // known-sensitive keys a misbehaving filter (or a future edit) might have
        // added. The recon-risk inventory must NEVER reach an unauthenticated
        // caller, even if a plugin gets the contract wrong.
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            unset($payload[$sensitive]);
        }

        return new JsonResponse($payload);
    }
}
