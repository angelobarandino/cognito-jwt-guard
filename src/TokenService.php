<?php

namespace Alsbury\CognitoGuard;

use Alsbury\CognitoGuard\Exceptions\InvalidTokenException;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;
use UnexpectedValueException;

use function count;
use function explode;

class TokenService
{
    protected String $uuidColumn;

    public function __construct()
    {
        $this->uuidColumn = config('cognito.uuid_column', 'sub');
    }


    public function getTokenFromRequest(Request $request): ?string
    {
        $jwt = $request->bearerToken();

        if(! $jwt){
            // from cookie
            $prefix = 'CognitoIdentityServiceProvider_' . config('cognito.user_pool_client_id');
            $sub = $request->cookie($prefix . '_LastAuthUser');
            $jwt = $request->cookie($prefix . '_' . $sub . '_accessToken');
        }

        return $jwt;
    }

    /**
     * @param string $jwt
     * @return string
     * @throws InvalidTokenException|Throwable
     */
    public function getCognitoUuidFromToken(string $jwt): string
    {
        
        $payload = $this->decode($jwt);

        $cognitoUuid = $payload->{$this->uuidColumn};
        throw_unless($cognitoUuid, new InvalidTokenException('CognitoUuid not found'));

        return $cognitoUuid;
    }

    /**
     * @param string $jwt
     * @return object
     * @throws InvalidTokenException
     */
    public function decode(string $jwt): object
    {
        $this->validateHeader($jwt);

        $endpoint           = config('cognito.endpoint');
        $validate_issuer    = config('cognito.validate_issuer');
        $region             = config('cognito.user_pool_region');
        $poolId             = config('cognito.user_pool_id');
        $js                 = app()->make(JwksService::class);
        $keys               = $js->getJwks($region, $poolId, $endpoint);

        try{
            // JWT::decode will throw an exception if the token is expired or otherwise invalid
            JWT::$leeway = 60;
            $payload = JWT::decode($jwt, $keys);
        }catch(
            InvalidArgumentException
            | UnexpectedValueException
            | SignatureInvalidException
            | BeforeValidException
            | ExpiredException
            | DomainException
            $e
        ){
            throw new InvalidTokenException($e->getMessage());
        }

        $this->validatePayload($payload, $region, $poolId, $validate_issuer);

        return $payload;
    }

    /**
     * Validates the header exists, can be base64 decoded, has a kid,
     * and has RS256 as alg
     *
     * @param string $jwt
     * @throws InvalidTokenException
     */
    public function validateHeader(string $jwt): void
    {
        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new InvalidTokenException('Wrong number of segments');
        }

        try{
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($tks[0]));
        }catch(DomainException $e
        ){
            throw new InvalidTokenException($e->getMessage());
        }

        if(empty($header->kid)){
            throw new InvalidTokenException('No kid present in token header');
        }

        if(empty($header->alg)){
            throw new InvalidTokenException('No alg present in token header');
        }

        if($header->alg !== 'RS256'){
            throw new InvalidTokenException('The token alg is not RS256');
        }
    }

    /**
     * Although we already know the token has a valid signature and is
     * unexpired, this method is used to validate the payload.
     *
     * @param object $payload
     * @param $region
     * @param $poolId
     * @return void
     * @throws InvalidTokenException | Throwable
     */
    public function validatePayload(object $payload, $region, $poolId, bool $validateIssuer): void
    {
        $issuer = sprintf('https://cognito-idp.%s.amazonaws.com/%s', $region, $poolId);
        // Validate the issuer
        if ($payload->iss !== $issuer && $validateIssuer) {
            throw new InvalidTokenException('Invalid issuer. Expected: ' . $issuer);
        }

        // Validate token_use
        if (!in_array($payload->token_use, ['id', 'access'])) {
            throw new InvalidTokenException('Invalid token_use. Must be one of ["id","access"].');
        }

        // Validate UUID presence
        if (!isset($payload->username) && !isset($payload->{$this->uuidColumn})) {
            throw new InvalidTokenException('Invalid token attributes. Token must include a column which contains the UUID.');
        }

        $uuid = $payload->{$this->uuidColumn};

        // Validate UUID format
        if (!Uuid::isValid($uuid) && !isset($payload->{$this->uuidColumn})) {
            throw new InvalidTokenException('Invalid token attributes. Parameters "username" and "cognito:username" must be a UUID.');
        }
    }
}
