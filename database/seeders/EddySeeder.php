<?php

namespace Database\Seeders;

use App\Models\Eddy\EddyKnowledge;
use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds Eddy's provider profiles, surface policies and a starter knowledge corpus.
 * Idempotent (updateOrCreate). Ships LOCAL-ONLY and cloud-gated: every surface has
 * allow_cloud = false until a super-admin flips it after a BAA is configured.
 *
 * Run standalone:  php artisan db:seed --class=Database\\Seeders\\EddySeeder
 */
class EddySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedProviderProfiles();
        $this->seedSurfacePolicies();
        $this->seedKnowledge();
    }

    private function seedProviderProfiles(): void
    {
        $profiles = [
            [
                'profile_id' => 'local-medgemma',
                'display_name' => 'MedGemma 27B (local, Ollama)',
                'provider_type' => 'ollama',
                'transport' => 'ollama_chat',
                'entitlement_type' => 'local',
                'model' => 'puyangwang/medgemma-27b-it:q4_0',
                'base_url' => 'http://host.docker.internal:11434',
                'is_enabled' => true,
                'capabilities' => ['chat', 'streaming', 'structured_output', 'long_context', 'ops_rag', 'patient_context_local_only'],
                'safety' => ['patient_level_context_allowed' => true], // on-prem; PHI-adjacent context is fine
                'limits' => ['timeout' => 180, 'max_output_tokens' => 1024],
                'fallback_profile_ids' => [],
            ],
            [
                'profile_id' => 'claude-frontier',
                'display_name' => 'Claude (frontier, Anthropic)',
                'provider_type' => 'anthropic',
                'transport' => 'anthropic_messages',
                'entitlement_type' => 'org_api_key',
                'model' => 'claude-sonnet-4-6',
                'base_url' => null,
                'is_enabled' => true, // enabled, but every surface ships allow_cloud=false (BAA-gated)
                'capabilities' => ['chat', 'streaming', 'structured_output', 'json_mode', 'tool_calling', 'agent_loop', 'long_context', 'vision'],
                'safety' => ['patient_level_context_allowed' => false], // never send patient-level context to cloud
                'limits' => ['timeout' => 60, 'max_output_tokens' => 4096, 'monthly_budget_usd' => 400],
                'fallback_profile_ids' => [],
            ],
            [
                'profile_id' => 'byo-openai',
                'display_name' => 'BYO OpenAI-compatible (disabled)',
                'provider_type' => 'openai',
                'transport' => 'openai_responses',
                'entitlement_type' => 'user_api_key',
                'model' => 'gpt-4.1',
                'base_url' => null,
                'is_enabled' => false,
                'capabilities' => ['chat', 'streaming', 'tool_calling'],
                'safety' => ['patient_level_context_allowed' => false],
                'limits' => ['timeout' => 60],
                'fallback_profile_ids' => [],
            ],
        ];

        foreach ($profiles as $p) {
            EddyProviderProfile::updateOrCreate(['profile_id' => $p['profile_id']], $p);
        }
    }

    private function seedSurfacePolicies(): void
    {
        // PHI-free aggregate surfaces: local-first, claude listed as fallback so a
        // single allow_cloud flip (post-BAA) activates frontier.
        $phiFreeAggregate = ['chat', 'command_center', 'improvement'];
        // Patient-level / operational surfaces: local-only until BAA + de-id review.
        $patientLevel = ['rtdc', 'ed', 'periop', 'transport', 'evs', 'staffing'];

        foreach ($phiFreeAggregate as $surface) {
            EddySurfacePolicy::updateOrCreate(['surface' => $surface], [
                'provider_mode' => 'local_first',
                'default_profile_id' => 'local-medgemma',
                'fallback_profile_ids' => ['claude-frontier'],
                'never_send_phi_to_cloud' => true,
                'allow_cloud' => false,                       // BAA-gated; flip per surface
                'required_capabilities' => ['chat', 'streaming'],
                'settings' => [],
            ]);
        }

        foreach ($patientLevel as $surface) {
            EddySurfacePolicy::updateOrCreate(['surface' => $surface], [
                'provider_mode' => 'local_only',
                'default_profile_id' => 'local-medgemma',
                'fallback_profile_ids' => [],
                'never_send_phi_to_cloud' => true,
                'allow_cloud' => false,
                'required_capabilities' => ['chat', 'streaming'],
                'settings' => [],
            ]);
        }

        // The agentic surface: frontier-default (needs agent_loop + tool_calling,
        // which the local MedGemma chat model lacks). No valid profile until a BAA
        // flips allow_cloud — Eddy's agent ships off, by design.
        EddySurfacePolicy::updateOrCreate(['surface' => 'eddy_agent'], [
            'provider_mode' => 'cloud_first',
            'default_profile_id' => 'claude-frontier',
            'fallback_profile_ids' => [],
            'never_send_phi_to_cloud' => true,
            'allow_cloud' => false,
            'required_capabilities' => ['agent_loop', 'tool_calling'],
            'settings' => [],
        ]);
    }

    private function seedKnowledge(): void
    {
        $docs = [
            [
                'surface' => 'global', 'category' => 'glossary',
                'title' => 'The RTDC bed meeting',
                'body' => 'The Real-Time Demand/Capacity (RTDC) bed meeting reconciles, per unit, predicted discharges (capacity), predicted demand (from ED/OR/transfer/direct), and current capacity into a signed `bed_need` (demand − capacity). A positive bed_need is a deficit; the plan lists the actions to close it.',
                'tags' => ['rtdc', 'capacity', 'bed_meeting'], 'source' => 'IHI RTDC method',
            ],
            [
                'surface' => 'rtdc', 'category' => 'playbook',
                'title' => 'Red stretch / surge escalation',
                'body' => 'When house-wide bed_need crosses the red threshold, open a surge (red stretch) plan: pull forward early discharges, expedite EVS turnovers (STAT first), hold non-urgent transfers, and escalate to the nursing supervisor. Reserve diversion as a last resort. Earned urgency: do not escalate routine deficits to red.',
                'tags' => ['surge', 'escalation', 'capacity'], 'source' => 'internal-policy',
            ],
            [
                'surface' => 'improvement', 'category' => 'sop',
                'title' => 'PDSA cycle basics',
                'body' => "A PDSA cycle: Plan a change against a measurable objective ('from X to Y by date'); Do the change on a small scale; Study the result against the measure (use SPC/control charts to separate signal from noise); Act — adopt, adapt, or abandon. Eddy drafts the Plan/Do/Study/Act fields from observed bottleneck data for human approval.",
                'tags' => ['pdsa', 'spc', 'improvement'], 'source' => 'IHI Model for Improvement',
            ],
            [
                'surface' => 'global', 'category' => 'policy',
                'title' => 'Advice, not autopilot',
                'body' => 'Eddy is a non-device operational decision-support tool. It produces operational suggestions, never clinical alerts (clinical alerting stays in the EHR). Every prescriptive output is an explainable suggestion with a runner-up and an override path. No Eddy tool mutates operational tables directly — writes are drafts requiring human approval.',
                'tags' => ['governance', 'non_device', 'safety'], 'source' => 'Eddy product doctrine',
            ],
        ];

        foreach ($docs as $d) {
            $row = EddyKnowledge::firstOrNew(['surface' => $d['surface'], 'title' => $d['title']]);
            if (! $row->exists) {
                $row->eddy_knowledge_uuid = (string) Str::uuid();  // stable across re-seeds
            }
            $row->fill(array_merge($d, ['is_phi_free' => true, 'is_active' => true]))->save();
        }
    }
}
