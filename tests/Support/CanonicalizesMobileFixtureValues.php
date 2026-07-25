<?php

namespace Tests\Support;

trait CanonicalizesMobileFixtureValues
{
    /**
     * Remove only database-sequence artifacts from a captured staff BFF payload.
     *
     * Factory test runs can begin after unrelated tests have consumed PostgreSQL
     * sequences. Those source IDs are not mobile-contract values; normalizing the
     * documented key/value grammar lets the fixture assertion fail for every
     * semantic payload change without flaking on a database sequence offset.
     */
    protected function canonicalizeMobileFixtureValue(mixed $value, ?string $key = null): mixed
    {
        /** @var array<string, int> $patientContextTokens */
        $patientContextTokens = [];

        return $this->canonicalizeMobileFixtureValueRecursively(
            $value,
            $key,
            $patientContextTokens,
        );
    }

    /**
     * @param  array<string, int>  $patientContextTokens
     */
    private function canonicalizeMobileFixtureValueRecursively(
        mixed $value,
        ?string $key,
        array &$patientContextTokens,
    ): mixed {
        if (is_array($value)) {
            $canonical = [];
            foreach ($value as $childKey => $childValue) {
                $canonical[$childKey] = $this->canonicalizeMobileFixtureValueRecursively(
                    $childValue,
                    is_string($childKey) ? $childKey : null,
                    $patientContextTokens,
                );
            }

            return $canonical;
        }

        if (is_int($value) && $value > 0 && in_array($key, [
            'actor_user_id',
            'bed_id',
            'bed_request_id',
            'id',
            'unit_id',
        ], true)) {
            return "<generated:{$key}>";
        }

        if (is_string($value) && in_array($key, ['entity_ref', 'ref'], true) && ctype_digit($value)) {
            return "<generated:{$key}>";
        }

        // Patient context handles are deliberately keyed with the runtime's
        // dedicated signing key. A fixture captures their opaque grammar and
        // placement, not a deploy-specific HMAC result. Normalizing them here
        // preserves repeated-reference relationships while keeping a
        // deterministic contract test when the test runtime creates a fresh
        // APP_KEY.
        if (is_string($value) && preg_match('/^ptok_[a-f0-9]{24}$/D', $value) === 1) {
            if (! array_key_exists($value, $patientContextTokens)) {
                $patientContextTokens[$value] = count($patientContextTokens) + 1;
            }

            return 'ptok_<generated:'.$patientContextTokens[$value].'>';
        }

        if (is_string($value) && $key === 'id'
            && preg_match('/^(bedreq|transport)-[1-9][0-9]*$/', $value, $matches) === 1) {
            return $matches[1].'-<generated>';
        }

        if (is_string($value) && $key === 'id'
            && preg_match('/^barrier-[1-9][0-9]*$/', $value) === 1) {
            return 'barrier-<generated>';
        }

        if (is_string($value) && $key === 'endpoint'
            && preg_match('#^/api/mobile/v1/rtdc/barriers/[1-9][0-9]*/resolve$#', $value) === 1) {
            return '/api/mobile/v1/rtdc/barriers/<generated>/resolve';
        }

        if (is_string($value) && in_array($key, ['plates_version', 'version'], true)
            && preg_match('/^v1-[a-f0-9]{12}$/', $value) === 1) {
            return 'v1-<generated-catalog-hash>';
        }

        return $value;
    }
}
