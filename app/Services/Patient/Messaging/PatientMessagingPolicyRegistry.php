<?php

namespace App\Services\Patient\Messaging;

use App\Contracts\Patient\PatientMessageHandoffReadiness;
use App\Models\Patient\PatientEncounterAccessGrant;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

class PatientMessagingPolicyRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly PatientMessageCipher $cipher,
    ) {}

    /**
     * Return the approved disclosure policy or fail closed. Reading existing
     * threads and presenting urgent-help guidance must not depend on the
     * readiness of the write-only handoff or current encryption key.
     *
     * @return array{
     *   policy_version: string,
     *   urgent_guidance_version: string,
     *   urgent_guidance_text: string,
     *   default_response_window: string,
     *   topics: array<string, array{label: string, description: string, responsibility_pool_key: string, composition_mode: string}>
     * }
     */
    public function disclosurePolicy(): array
    {
        $config = (array) config('hummingbird-patient.messaging', []);

        if (($config['governance_status'] ?? null) !== 'approved') {
            throw PatientMessagingFailure::unavailable();
        }

        $policy = [
            'policy_version' => $this->requiredString($config, 'policy_version', 120),
            'urgent_guidance_version' => $this->requiredString($config, 'urgent_guidance_version', 120),
            'urgent_guidance_text' => $this->requiredString($config, 'urgent_guidance_text', 500, 20),
            'default_response_window' => $this->requiredString($config, 'default_response_window', 240, 10),
            'topics' => [],
        ];

        $topics = $config['topics'] ?? null;
        if (! is_array($topics) || $topics === []) {
            throw PatientMessagingFailure::unavailable();
        }

        foreach ($topics as $code => $topic) {
            if (! is_string($code)
                || preg_match('/^[a-z][a-z0-9_]{1,78}[a-z0-9]$/', $code) !== 1
                || ! is_array($topic)
            ) {
                throw PatientMessagingFailure::unavailable();
            }

            // The rounds-question topic has an independently fail-closed
            // patient-composition gate. Filtering it here means neither the
            // topic list nor create-thread accepts it while disabled.
            if ($code === 'rounds_question'
                && ! (bool) config('hummingbird-patient.features.rounds_questions')
            ) {
                continue;
            }

            $policy['topics'][$code] = [
                'label' => $this->requiredString($topic, 'label', 120, 3),
                'description' => $this->requiredString($topic, 'description', 300, 10),
                'responsibility_pool_key' => $this->requiredString(
                    $topic,
                    'responsibility_pool_key',
                    120,
                    3,
                ),
                'composition_mode' => $this->compositionMode($topic),
            ];
        }

        return $policy;
    }

    /**
     * Return the approved policy only when every dependency required to accept
     * new patient content is ready. This is deliberately stricter than the
     * disclosure policy.
     *
     * @return array{
     *   policy_version: string,
     *   urgent_guidance_version: string,
     *   urgent_guidance_text: string,
     *   default_response_window: string,
     *   encryption_key_version: string,
     *   topics: array<string, array{label: string, description: string, responsibility_pool_key: string, composition_mode: string}>
     * }
     */
    public function mutationPolicy(): array
    {
        $config = (array) config('hummingbird-patient.messaging', []);
        $policy = $this->contentWritePolicy();

        if (! $this->consumer($config)->readyForPolicy($policy['policy_version'])) {
            throw PatientMessagingFailure::unavailable();
        }

        return $policy;
    }

    /**
     * Validate the governance policy and current encryption key for a staff
     * response to an already-routed thread. A handoff-worker outage must not
     * prevent the accountable care team from answering work it already owns.
     *
     * @return array<string, mixed>
     */
    public function contentWritePolicy(): array
    {
        $config = (array) config('hummingbird-patient.messaging', []);
        $policy = $this->disclosurePolicy();
        $policy['encryption_key_version'] = $this->requiredString(
            $config,
            'encryption_key_version',
            80,
        );

        try {
            $this->cipher->assertAvailable($policy['encryption_key_version']);
        } catch (RuntimeException) {
            throw PatientMessagingFailure::unavailable();
        }

        return $policy;
    }

    /**
     * Backwards-compatible strict policy accessor for callers that accept new
     * content. New read paths should call disclosurePolicy() explicitly.
     *
     * @return array<string, mixed>
     */
    public function approvedPolicy(): array
    {
        return $this->mutationPolicy();
    }

    /** @return array{label: string, description: string, responsibility_pool_key: string, composition_mode: string} */
    public function topic(string $code): array
    {
        $policy = $this->mutationPolicy();
        $topic = $policy['topics'][$code] ?? null;

        if (! is_array($topic)) {
            throw PatientMessagingFailure::notFound();
        }

        return $topic;
    }

    /**
     * A generally healthy handoff is insufficient: the locked encounter must
     * be routable for this exact policy/topic before content is accepted.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $topic
     */
    public function assertMutationRoutable(
        array $policy,
        string $topicCode,
        array $topic,
        PatientEncounterAccessGrant $grant,
    ): void {
        $policyVersion = $policy['policy_version'] ?? null;
        $poolKey = $topic['responsibility_pool_key'] ?? null;

        if (! is_string($policyVersion)
            || ! is_string($poolKey)
            || ! $this->consumer((array) config('hummingbird-patient.messaging', []))->routableForGrant(
                $policyVersion,
                $topicCode,
                $poolKey,
                $grant,
            )
        ) {
            throw PatientMessagingFailure::unavailable();
        }
    }

    /** @param array<string, mixed> $config */
    private function consumer(array $config): PatientMessageHandoffReadiness
    {
        $consumerClass = $config['handoff_consumer'] ?? null;
        if (! is_string($consumerClass)
            || ! is_subclass_of($consumerClass, PatientMessageHandoffReadiness::class)
        ) {
            throw PatientMessagingFailure::unavailable();
        }

        $consumer = $this->container->make($consumerClass);
        if (! $consumer instanceof PatientMessageHandoffReadiness) {
            throw PatientMessagingFailure::unavailable();
        }

        return $consumer;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requiredString(
        array $source,
        string $key,
        int $maximumLength,
        int $minimumLength = 1,
    ): string {
        $value = trim(is_string($source[$key] ?? null) ? $source[$key] : '');

        if (mb_strlen($value) < $minimumLength || mb_strlen($value) > $maximumLength) {
            throw PatientMessagingFailure::unavailable();
        }

        return $value;
    }

    /** @param array<string, mixed> $topic */
    private function compositionMode(array $topic): string
    {
        $mode = $topic['composition_mode'] ?? 'direct';

        if (! is_string($mode) || ! in_array($mode, ['direct', 'released_education_only'], true)) {
            throw PatientMessagingFailure::unavailable();
        }

        return $mode;
    }
}
