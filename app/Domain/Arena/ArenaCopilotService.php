<?php

namespace App\Domain\Arena;

use App\Domain\Arena\Copilot\ArenaQueryCatalog;
use App\Domain\Arena\Copilot\CopilotLlm;
use App\Domain\Ocel\OcelJsonExporter;
use App\Models\User;
use App\Services\Eddy\EddyActionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Part X (X4) — the governed AI copilot orchestrator (§X.8). Four capabilities,
 * each with a DETERMINISTIC path and an OPTIONAL LLM enhancement, all gated by
 * ARENA_AI_ENABLED and all reasoning over de-identified aggregates only:
 *
 *   narrate()      — a provenance-pinned brief of the log's scale, bottleneck, and
 *                    conformance (assembled from the DB; the LLM only polishes prose).
 *   query()        — answers a question by SELECTING an allow-listed, parameterized
 *                    query (never free-form SQL); the LLM only routes to a query id.
 *   authorMap()    — proposes an object-centric map and WITHHOLDS it below the
 *                    conformance-fitness floor (the sidecar pm4py adjudicates).
 *   draftPdsa() / draftCorrection() — draft a governed action that lands PENDING on
 *                    the Eddy plane (propose(approve:false)); the copilot never approves.
 *
 * The whole service is inert when the copilot is off — every method short-circuits
 * to an `available:false` envelope, mirroring the deterministic Arena panes.
 */
class ArenaCopilotService
{
    public function __construct(
        private readonly OcelJsonExporter $exporter,
        private readonly ArenaSidecarClient $client,
        private readonly CopilotLlm $llm,
        private readonly ArenaQueryCatalog $catalog,
        private readonly EddyActionService $eddyActions,
    ) {}

    /** The copilot needs both the Arena (X0-X3) AND its own independent AI flag. */
    public function enabled(): bool
    {
        return (bool) config('services.arena.enabled') && (bool) config('services.arena.ai_enabled');
    }

    private function unavailable(): array
    {
        return ['available' => false, 'reason' => 'copilot_disabled'];
    }

    /**
     * A plain-language brief whose every claim is pinned to a real metric. Built
     * from the DB (log counts + the cached Arena signals) so it never depends on the
     * sidecar being up. The LLM, when live, rewrites the prose but can NEVER change
     * the provenance numbers — those are surfaced beside the narrative (§X.8.2).
     */
    public function narrate(): array
    {
        if (! $this->enabled()) {
            return $this->unavailable();
        }

        $provenance = $this->narrativeFacts();
        $deterministic = $this->assembleNarrative($provenance);

        $narrative = $deterministic;
        $polished = false;
        if ($this->llm->isLive()) {
            $prompt = 'Rewrite this hospital operations brief as three crisp sentences for a command-center audience. '
                ."Do NOT invent, add, or alter any number — use only the figures given.\n\n".$deterministic;
            $out = $this->llm->generate($this->systemPrompt(), $prompt, ['max_tokens' => 400]);
            if (is_string($out) && trim($out) !== '') {
                $narrative = trim($out);
                $polished = true;
            }
        }

        return [
            'available' => true,
            'narrative' => $narrative,
            'provenance' => $provenance,
            'ai_polished' => $polished,
            'generated_label' => 'AI-generated · pinned to live metrics',
        ];
    }

    /**
     * Answer a question by routing it onto an allow-listed query. The LLM (if live)
     * proposes a {query_id, params}; a deterministic keyword router is the fallback.
     * Either way the ArenaQueryCatalog validates and binds every parameter — there is
     * no path from the question text to SQL.
     */
    public function query(string $question): array
    {
        if (! $this->enabled()) {
            return $this->unavailable();
        }

        $routed = $this->routeQuery($question);
        if ($routed === null || ! $this->catalog->has($routed['query_id'])) {
            return [
                'available' => true,
                'matched' => false,
                'question' => $question,
                'suggestions' => $this->catalog->describe(),
                'message' => 'No allow-listed query matches that question. Pick one of the available queries.',
            ];
        }

        try {
            $result = $this->catalog->run($routed['query_id'], $routed['params']);
        } catch (\Throwable $e) {
            // Never reflect the raw exception (PDO / validation text) to the client.
            Log::warning('arena.copilot.query_failed', ['query_id' => $routed['query_id'], 'error' => $e->getMessage()]);

            return ['available' => true, 'matched' => false, 'question' => $question, 'message' => 'That question could not be answered by an allow-listed query.'];
        }

        return ['available' => true, 'matched' => true, 'question' => $question, 'routed_by' => $routed['routed_by']] + $result;
    }

    /**
     * Propose an object-centric map and adjudicate it against the data. The sidecar
     * computes DFG-fitness; a map below the floor is WITHHELD (returned with its
     * fitness but no map body) — the AI may propose, the data decides (§X.8.2).
     */
    public function authorMap(?array $objectTypes = null): array
    {
        if (! $this->enabled()) {
            return $this->unavailable();
        }

        $ocel = $this->exporter->export();
        $discovered = $this->client->discover($ocel, $objectTypes);
        if ($discovered === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        $proposedEdges = array_map(
            fn (array $e): array => ['object_type' => $e['object_type'], 'source' => $e['source'], 'target' => $e['target']],
            $discovered['edges'] ?? []
        );

        $fitness = $this->client->modelFitness($ocel, $proposedEdges);
        if ($fitness === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        $published = (bool) ($fitness['published'] ?? false);

        return [
            'available' => true,
            'published' => $published,
            'fitness' => $fitness['fitness'] ?? 0.0,
            'precision' => $fitness['precision'] ?? 0.0,
            'fitness_floor' => $fitness['fitness_floor'] ?? config('services.arena.ai_fitness_floor'),
            'reason' => $fitness['reason'] ?? null,
            // Honest provenance: v1 authors a map by DISCOVERING and validating it
            // against the data (not LLM-generated prose), so it does not claim
            // "AI-authored". The fitness gate still withholds any proposed map below
            // the floor — proven on hallucinated models in the sidecar eval suite.
            'generated_label' => $published ? 'Conformance-validated map' : 'Withheld — below the fitness floor',
            // The map body is surfaced ONLY when it conforms; below the floor we
            // return the score + evidence but never the unvalidated picture.
            'map' => $published ? $discovered : null,
            'invented_edges' => $fitness['invented_edges'] ?? [],
            'missing_edges' => $fitness['missing_edges'] ?? [],
        ];
    }

    /**
     * Draft a PDSA cycle for a bottleneck/deviation focus. The drafted plan rides in
     * the governance action's payload and lands as Recommendation(draft) →
     * OperationalAction(draft) → Approval(pending) via the Eddy plane — no
     * prod.pdsa_cycles row is written until a human approves (§X.8.3).
     */
    public function draftPdsa(User $actor, string $focus, ?int $barrierId = null): array
    {
        if (! $this->enabled()) {
            return $this->unavailable();
        }

        $content = $this->buildPdsaDraft($focus);
        if ($content === null) {
            return ['available' => true, 'drafted' => false, 'reason' => 'no_signal_for_focus'];
        }

        $proposal = $this->eddyActions->propose($actor, [
            'action_type' => 'propose_pdsa_cycle',
            'title' => $content['title'],
            'rationale' => $content['hypothesis'],
            'surface' => 'arena',
            // Part X seam 3: carry the barrier (if the draft was raised from one) so
            // approval can link the resulting PDSA cycle back to it.
            'barrier_id' => $barrierId,
            'params' => ['pdsa' => $content, 'focus' => $focus, 'proposed_status' => 'planned'],
            'runner_up' => $content['runner_up'],
            'alert_key' => null,
        ], approve: false);

        return ['available' => true, 'drafted' => true, 'pdsa' => $content, 'action' => $proposal];
    }

    /**
     * Draft a governed pathway-correction for a conformance deviation. Lands pending
     * on the Eddy plane exactly like a PDSA draft.
     */
    public function draftCorrection(User $actor, string $pathway, ?int $barrierId = null): array
    {
        if (! $this->enabled()) {
            return $this->unavailable();
        }

        $row = DB::table('arena.conformance_signals')->where('pathway', $pathway)->first();
        if ($row === null) {
            return ['available' => true, 'drafted' => false, 'reason' => 'no_signal_for_pathway'];
        }

        $rate = (float) $row->value;
        $title = "Correct {$pathway} pathway deviation";
        $rationale = "Object-centric conformance for {$pathway} is {$rate}% ({$row->deviant}/{$row->cases} cases deviated). "
            .'A governed correction is proposed for human review.';

        $proposal = $this->eddyActions->propose($actor, [
            'action_type' => 'propose_pathway_correction',
            'title' => $title,
            'rationale' => $rationale,
            'surface' => 'arena',
            // Part X seam 3: link back to the barrier when the correction was raised from one.
            'barrier_id' => $barrierId,
            'params' => ['pathway' => $pathway, 'conformance_pct' => $rate, 'deviant' => (int) $row->deviant, 'cases' => (int) $row->cases],
            'runner_up' => 'Escalate the pathway to the quality lead for manual review.',
            'alert_key' => null,
        ], approve: false);

        return ['available' => true, 'drafted' => true, 'action' => $proposal];
    }

    // ---- deterministic assembly (PHI-free, DB-only) ---------------------------

    /** @return list<array{claim:string, source:string, value:string}> */
    private function narrativeFacts(): array
    {
        $facts = [];

        try {
            $events = (int) DB::table('ocel.events')->count();
            $objects = (int) DB::table('ocel.objects')->count();
            $types = (int) DB::table('ocel.object_types')->count();
            $facts[] = ['claim' => 'log scale', 'source' => 'ocel.events / ocel.objects', 'value' => "{$events} events · {$objects} objects · {$types} object types"];
        } catch (\Throwable $e) {
            Log::warning('arena.copilot.narrative_read_failed', ['stage' => 'scale', 'error' => $e->getMessage()]);
        }

        try {
            $bottleneck = DB::table('arena.performance_signals')->where('metric_key', 'flow.worst_handoff_wait')->first();
            if ($bottleneck !== null) {
                $facts[] = ['claim' => 'worst hand-off wait', 'source' => 'arena.performance_signals', 'value' => round((float) $bottleneck->value).' min at '.$bottleneck->context];
            }
        } catch (\Throwable) {
        }

        try {
            foreach (DB::table('arena.conformance_signals')->orderBy('pathway')->get() as $row) {
                $facts[] = ['claim' => "{$row->pathway} conformance", 'source' => 'arena.conformance_signals', 'value' => round((float) $row->value, 1)."% ({$row->deviant}/{$row->cases} deviant)"];
            }
        } catch (\Throwable) {
        }

        return $facts;
    }

    /** @param  list<array{claim:string, source:string, value:string}>  $facts */
    private function assembleNarrative(array $facts): string
    {
        if ($facts === []) {
            return 'The object-centric log is empty or unavailable — no process narrative can be assembled yet.';
        }

        $parts = [];
        foreach ($facts as $f) {
            $parts[] = ucfirst($f['claim']).': '.$f['value'].'.';
        }

        return implode(' ', $parts);
    }

    /** @return array<string, string>|null */
    private function buildPdsaDraft(string $focus): ?array
    {
        if ($focus === 'bottleneck') {
            $row = DB::table('arena.performance_signals')->where('metric_key', 'flow.worst_handoff_wait')->first();
            if ($row === null) {
                return null;
            }
            $ctx = (string) $row->context;
            $val = round((float) $row->value);

            return [
                'title' => "Reduce hand-off wait — {$ctx}",
                'objective' => "Cut the worst object-side hand-off wait ({$val} min) at {$ctx}.",
                'hypothesis' => "The synchronization constraint is {$ctx}; reducing the wait there should relieve downstream flow.",
                'plan' => "Instrument the {$ctx} hand-off, pilot a pull-signal / parallelized preparation, and measure the median wait weekly.",
                'prediction' => "A 25% reduction in the {$ctx} wait within four PDSA weeks.",
                'runner_up' => 'Escalate to the flow huddle for a manual root-cause on the hand-off.',
                'proposed_status' => 'planned',
            ];
        }

        $pathway = in_array($focus, ['sepsis', 'surgical_safety'], true) ? $focus : null;
        if ($pathway === null) {
            return null;
        }
        $row = DB::table('arena.conformance_signals')->where('pathway', $pathway)->first();
        if ($row === null) {
            return null;
        }
        $rate = round((float) $row->value, 1);

        return [
            'title' => "Raise {$pathway} bundle conformance",
            'objective' => "Improve {$pathway} pathway conformance from {$rate}% toward the reference target.",
            'hypothesis' => "The {$pathway} deviations cluster at a specific bundle step; a checklist reinforcement should raise conformance.",
            'plan' => "Audit the top deviation for {$pathway}, pilot a step-level prompt, and re-measure conformance each week.",
            'prediction' => "A 10-point conformance lift for {$pathway} within four PDSA weeks.",
            'runner_up' => 'Refer to the quality committee for a formal pathway review.',
            'proposed_status' => 'planned',
        ];
    }

    /**
     * @return array{query_id:string, params:array<string,mixed>, routed_by:string}|null
     */
    private function routeQuery(string $question): ?array
    {
        // Deterministic keyword routing runs FIRST and entirely on-box — most
        // questions never reach the model. Only an unmatched question is handed to
        // the LLM, and only after EddyProxyCopilotLlm's PHI scan (§X.8.2). Residual:
        // a free-text name in an UNMATCHED question can reach the LOCAL model past
        // the structured-PHI regex; the allow-list still bounds what any answer can
        // touch (read-only aggregates), and no third party is ever in the loop.
        $deterministic = $this->catalog->resolve($question);
        if ($deterministic !== null) {
            return $deterministic + ['routed_by' => 'keyword'];
        }

        if ($this->llm->isLive()) {
            $llmRoute = $this->llmRouteQuery($question);
            if ($llmRoute !== null) {
                return $llmRoute + ['routed_by' => 'llm'];
            }
        }

        return null;
    }

    /**
     * Ask the LLM to CHOOSE a query id + params (JSON only). Any malformed or
     * unknown response is discarded and the deterministic router takes over — the
     * LLM can never widen the allow-list.
     *
     * @return array{query_id:string, params:array<string,mixed>}|null
     */
    private function llmRouteQuery(string $question): ?array
    {
        $catalogJson = json_encode($this->catalog->describe());
        $system = $this->systemPrompt()."\nYou map a question to ONE query from this allow-list and return ONLY compact JSON "
            .'{"query_id":"...","params":{...}}. If nothing fits, return {"query_id":null}. Allow-list: '.$catalogJson;

        $out = $this->llm->generate($system, $question, ['json' => true, 'max_tokens' => 200]);
        if (! is_string($out)) {
            return null;
        }

        $decoded = json_decode($out, true);
        if (! is_array($decoded) || ! isset($decoded['query_id']) || ! is_string($decoded['query_id'])) {
            return null;
        }
        if (! $this->catalog->has($decoded['query_id'])) {
            return null;
        }

        return ['query_id' => $decoded['query_id'], 'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : []];
    }

    private function systemPrompt(): string
    {
        return 'You are the Zephyrus Patient-Flow Arena copilot. You summarize object-centric process-mining '
            .'results for a hospital command center. You reason only over de-identified aggregates and activity '
            .'labels — never patient data. You propose; a human approves.';
    }
}
