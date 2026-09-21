<?php

namespace Leantime\Core\Middleware;

use Closure;
use Leantime\Core\Events\DispatchesEvents;
use Leantime\Core\Http\IncomingRequest;
use Symfony\Component\HttpFoundation\Response;

class InitialHeaders
{
    use DispatchesEvents;

    /**
     * Set up the initial headers
     *
     * @param  \Closure(IncomingRequest): Response  $next
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     **/
    public function handle($request, Closure $next): Response
    {

        $response = $next($request);

        // Content Security Policy
        $cspParts = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src 'self' https: data: blob:",
            "connect-src 'self' https: wss:",
            // Allow all embed providers supported by the TipTap embed extension.
            // Each entry corresponds to one or more embed types in embed.js.
            "frame-src 'self'"
                .' *.google.com'                          // googleDocs, googleSheets, googleSlides, googleForms
                .' *.microsoft.com *.live.com *.sharepoint.com *.officeapps.live.com' // oneDrive, office365
                .' *.figma.com'                           // figma
                .' *.miro.com'                            // miro
                .' *.youtube.com *.youtube-nocookie.com'  // youtube
                .' player.vimeo.com *.vimeo.com'          // vimeo
                .' *.loom.com'                            // loom
                .' *.airtable.com'                        // airtable
                .' *.typeform.com form.typeform.com'      // typeform
                .' calendly.com'                          // calendly
                .' codepen.io'                            // codepen
                .' *.codesandbox.io',                     // codesandbox
            "media-src 'self' blob: https:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];
        $cspParts = self::dispatchFilter('cspParts', $cspParts);
        $csp = implode(';', $cspParts);

        foreach (
            self::dispatchFilter('headers', [
                'X-Frame-Options' => 'DENY',
                'X-XSS-Protection' => '1; mode=block',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'same-origin',
                'Access-Control-Allow-Origin' => BASE_URL,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Content-Security-Policy' => $csp,
            ]) as $key => $value
        ) {
            if ($response->headers->has($key)) {
                continue;
            }

            $response->headers->set($key, $value);
        }

        if ($request->isSecure() || env('JULIANNA_HSTS_ENABLED', false)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
