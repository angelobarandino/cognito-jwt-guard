<?php

namespace Alsbury\CognitoGuard;

use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A class for downloading and caching the Cognito JWKS for the given user pool and
 * region.
 *
 * @package Alsbury\CognitoGuard
 */
class JwksService
{
    /**
     * @param string $region
     * @param string $poolId
     * @param string $endpoint
     * @return array
     */
    public function getJwks(string $region, string $poolId, string $endpoint): array
    {
        $json = Cache::remember('cognito:jwks-' . $poolId, 3600, function () use($region, $poolId, $endpoint) {
            return $this->downloadJwks($region, $poolId, $endpoint);
        });

        $keys = json_decode($json, true);
        return JWK::parseKeySet($keys);
    }

    /**
     * Download the jwks for the configured user pool
     *
     * @param string $region
     * @param string $poolId
     * @param string $endpoint
     * @return string
     */
    public function downloadJwks(string $region, string $poolId, string $endpoint = null): string
    {
        // Use the provided endpoint if available; otherwise, use the default URL.
        if ($endpoint) {
            $url = sprintf('%s/%s/.well-known/jwks.json', rtrim($endpoint, '/'), $poolId);
        } else {
            $url = sprintf('https://cognito-idp.%s.amazonaws.com/%s/.well-known/jwks.json', $region, $poolId);
        }

        $response = Http::get($url);
        $response->throw();

        return $response->body();
    }

}
