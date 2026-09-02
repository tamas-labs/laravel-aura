<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use TamasLabs\AuraSchema\AuraSchema;

/**
 * Validates payloads against the Aura wire contract shipped by
 * `tamas-labs/aura-schema`.
 *
 * The schema documents carry absolute `$id`s pointing at GitHub. Those are
 * identity, not a download location: the resolver maps the whole base URI onto
 * the package's own directory, so every cross-file `$ref` is answered from disk
 * and a test run never touches the network.
 */
final class ContractValidator
{
    private static ?Validator $validator = null;

    /**
     * Validate a full API response.
     *
     * @param  mixed  $payload  Typically the decoded body of a JSON response.
     */
    public static function response(mixed $payload): ContractResult
    {
        return self::validate($payload, 'aura-response.schema.json');
    }

    /**
     * Validate a full API request — what Aura sends on every fetch.
     *
     * @param  mixed  $payload  Typically the decoded request body or query.
     */
    public static function request(mixed $payload): ContractResult
    {
        return self::validate($payload, 'aura-request.schema.json');
    }

    /**
     * Validate one batch of reported errors — what Aura POSTs to the ingest
     * endpoint.
     *
     * @param  mixed  $payload  Typically the decoded request body.
     */
    public static function errorReport(mixed $payload): ContractResult
    {
        return self::validate($payload, 'aura-error-report.schema.json');
    }

    /**
     * Validate against one document of the contract.
     *
     * @param  string  $document  File name relative to the schema directory.
     */
    public static function validate(mixed $payload, string $document): ContractResult
    {
        $result = self::validator()->validate(
            Helper::toJSON($payload),
            AuraSchema::BASE_URI.$document,
        );

        $error = $result->error();

        if ($error === null) {
            return new ContractResult(true, []);
        }

        $issues = [];

        /** @var array<string, list<string>> $keyed */
        $keyed = (new ErrorFormatter)->formatKeyed($error);

        foreach ($keyed as $path => $messages) {
            foreach ($messages as $message) {
                $issues[] = sprintf('%s: %s', $path === '' ? '/' : $path, $message);
            }
        }

        return new ContractResult(false, $issues);
    }

    /**
     * The shared validator, with the contract's base URI mapped onto the
     * package directory.
     */
    private static function validator(): Validator
    {
        if (self::$validator instanceof Validator) {
            return self::$validator;
        }

        $validator = new Validator;
        $validator->resolver()?->registerPrefix(AuraSchema::BASE_URI, AuraSchema::directory());

        return self::$validator = $validator;
    }
}
