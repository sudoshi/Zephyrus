<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileUiVocabularyParityTest extends TestCase
{
    private const CANONICAL_CATALOG = 'docs/hummingbird/role-catalog.v1.json';

    private const OPENAPI = 'docs/hummingbird/api-contract/hummingbird-bff.v1.yaml';

    private const EDDY_ACTION_SERVICE = 'app/Services/Eddy/EddyActionService.php';

    private const IOS_THEME = 'hummingbird/iosApp/Hummingbird/DesignSystem/Theme.swift';

    private const IOS_STATUS = 'hummingbird/iosApp/Hummingbird/DesignSystem/CapacityStatus.swift';

    private const IOS_STATUS_CHIP = 'hummingbird/iosApp/Hummingbird/DesignSystem/Components/StatusChip.swift';

    private const IOS_MODELS = 'hummingbird/iosApp/Hummingbird/Networking/Models.swift';

    private const ANDROID_API_CLIENT = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/ApiClient.kt';

    private const ANDROID_STATUS = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/theme/CapacityStatus.kt';

    private const ANDROID_STATUS_CHIP = 'hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/components/StatusChip.kt';

    public function test_status_values_are_canonical_across_openapi_ios_and_android(): void
    {
        $expected = $this->statusIds();

        $this->assertSame($expected, $this->openApiStatusValues(), 'OpenAPI StatusValue drifted.');
        $this->assertSame($expected, $this->swiftCapacityStatusValues(), 'iOS CapacityStatus drifted.');
        $this->assertSame($expected, $this->androidCapacityStatusValues(), 'Android CapacityStatus drifted.');
    }

    public function test_status_chip_label_icon_and_color_mappings_match_shared_contract(): void
    {
        $this->assertSame($this->statusMap('capacity_label'), $this->swiftStatusLabels(), 'iOS status labels drifted.');
        $this->assertSame($this->statusMap('capacity_label'), $this->androidStatusLabels(), 'Android status labels drifted.');
        $this->assertSame($this->statusMap('ios_symbol'), $this->swiftStatusSymbols(), 'iOS status symbols drifted.');
        $this->assertSame($this->statusMap('android_icon'), $this->androidStatusIcons(), 'Android status icons drifted.');
        $this->assertSame($this->statusMap('color_token'), $this->swiftStatusColorTokens(), 'iOS status color tokens drifted.');
        $this->assertSame($this->statusMap('color_token'), $this->androidStatusColorTokens(), 'Android status color tokens drifted.');

        $iosChip = file_get_contents(base_path(self::IOS_STATUS_CHIP));
        $androidChip = file_get_contents(base_path(self::ANDROID_STATUS_CHIP));

        $this->assertStringContainsString('Image(systemName: status.symbol)', $iosChip);
        $this->assertStringContainsString('Text(status.label.uppercased())', $iosChip);
        $this->assertStringContainsString('foregroundStyle(Z.status(status))', $iosChip);
        $this->assertStringContainsString('Icon(status.icon', $androidChip);
        $this->assertStringContainsString('Text(', $androidChip);
        $this->assertStringContainsString('status.label.uppercase()', $androidChip);
        $this->assertStringContainsString('status.color', $androidChip);
    }

    public function test_urgency_tiers_are_canonical_and_distinct_from_visual_status(): void
    {
        $expected = $this->urgencyTierIds();

        $this->assertSame([], array_values(array_intersect($this->statusIds(), $expected)));
        foreach ($this->openApiUrgencyEnums() as $enum) {
            $this->assertSame($expected, $enum, 'OpenAPI Eddy urgency tier enum drifted.');
        }

        $eddyCatalogTiers = $this->eddyCatalogTiers();
        $this->assertSame([], array_values(array_diff($eddyCatalogTiers, $expected)), 'Eddy catalog uses a non-canonical urgency tier.');
        $this->assertSame([], array_values(array_intersect($eddyCatalogTiers, $this->statusIds())), 'Eddy catalog tier is using a visual status value.');
    }

    public function test_product_specific_status_label_sets_are_canonical(): void
    {
        $sets = $this->canonicalDocument()['status_label_sets'];
        $statusIds = $this->statusIds();

        $this->assertSame(['Within capacity', 'Near capacity', 'At capacity', 'No data'], array_column($sets['capacity'], 'label'));
        $this->assertSame(['Routine', 'At risk', 'Overdue', 'STAT'], array_column($sets['task_queue'], 'label'));
        $this->assertSame(['Pending', 'Approved', 'Rejected', 'Expired'], array_column($sets['approval'], 'label'));

        foreach ($sets as $setName => $rows) {
            foreach ($rows as $row) {
                $this->assertContains($row['status'], $statusIds, "{$setName}.{$row['id']} uses a non-canonical visual status.");
            }
        }
    }

    public function test_visual_status_is_explicit_and_clients_treat_tier_as_legacy_fallback(): void
    {
        $openApi = file_get_contents(base_path(self::OPENAPI));
        $iosModels = file_get_contents(base_path(self::IOS_MODELS));
        $androidClient = file_get_contents(base_path(self::ANDROID_API_CLIENT));

        preg_match_all('/visual_status:\s*\{\s*\$ref:\s*["\']#\/components\/schemas\/StatusValue["\']\s*\}/', $openApi, $visualStatusRefs);
        $this->assertGreaterThanOrEqual(6, count($visualStatusRefs[0]), 'OpenAPI must expose explicit visual_status fields for mobile visual status payloads.');
        $this->assertDoesNotMatchRegularExpression('/tier:\s*\{\s*\$ref:\s*["\']#\/components\/schemas\/StatusValue["\']\s*\}/', $openApi, 'StatusValue tier fields must be documented as legacy aliases.');

        $this->assertStringContainsString('let visualStatus: String?', $iosModels);
        $this->assertStringContainsString('CapacityStatus(apiValue: visualStatus ??', $iosModels);
        $this->assertStringNotContainsString('CapacityStatus(apiValue: tier)', $iosModels);

        $this->assertStringContainsString('o.optStringOrNull("visual_status")', $androidClient);
        $this->assertStringContainsString('?: o.optStringOrNull("tier")', $androidClient);
    }

    /**
     * @return array<int, string>
     */
    private function statusIds(): array
    {
        return array_column($this->canonicalDocument()['status_vocabulary'], 'id');
    }

    /**
     * @return array<int, string>
     */
    private function urgencyTierIds(): array
    {
        return array_column($this->canonicalDocument()['urgency_tiers'], 'id');
    }

    /**
     * @return array<string, string>
     */
    private function statusMap(string $field): array
    {
        return collect($this->canonicalDocument()['status_vocabulary'])
            ->mapWithKeys(fn (array $status): array => [$status['id'] => $status[$field]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function openApiStatusValues(): array
    {
        preg_match('/StatusValue:\s*\R\s*type:\s*string\s*\R\s*enum:\s*\[([^\]]+)\]/', file_get_contents(base_path(self::OPENAPI)), $matches);
        $this->assertNotEmpty($matches, 'Unable to find OpenAPI StatusValue enum.');

        return $this->parseInlineList($matches[1]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function openApiUrgencyEnums(): array
    {
        preg_match_all('/tier:\s*\{\s*type:\s*string,\s*enum:\s*\[([^\]]+)\]\s*\}/', file_get_contents(base_path(self::OPENAPI)), $matches);
        $this->assertNotEmpty($matches[1], 'Unable to find OpenAPI urgency tier enums.');

        return collect($matches[1])
            ->map(fn (string $enum): array => $this->parseInlineList($enum))
            ->filter(fn (array $enum): bool => in_array('T1', $enum, true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function swiftCapacityStatusValues(): array
    {
        $block = $this->between(file_get_contents(base_path(self::IOS_STATUS)), 'enum CapacityStatus: String {', 'init(apiValue:');
        preg_match('/case\s+([^\n]+)/', $block, $matches);
        $this->assertNotEmpty($matches, 'Unable to find iOS CapacityStatus cases.');

        return $this->parseInlineList($matches[1]);
    }

    /**
     * @return array<int, string>
     */
    private function androidCapacityStatusValues(): array
    {
        $block = $this->between(file_get_contents(base_path(self::ANDROID_STATUS)), 'enum class CapacityStatus {', 'val color:');
        preg_match_all('/\b(SUCCESS|WARNING|CRITICAL|INFO)\b/', $block, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $value): string => strtolower($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function swiftStatusLabels(): array
    {
        $block = $this->between(file_get_contents(base_path(self::IOS_STATUS)), 'var label: String {', 'var symbol:');

        return $this->swiftReturnMap($block);
    }

    /**
     * @return array<string, string>
     */
    private function swiftStatusSymbols(): array
    {
        $block = $this->between(file_get_contents(base_path(self::IOS_STATUS)), 'var symbol: String {', '/// Severity rank');

        return $this->swiftReturnMap($block);
    }

    /**
     * @return array<string, string>
     */
    private function swiftStatusColorTokens(): array
    {
        $block = $this->between(file_get_contents(base_path(self::IOS_THEME)), 'static func status(_ s: CapacityStatus) -> Color {', '// MARK: - Hex parsing');
        preg_match_all('/case\s+\.(\w+):\s+return\s+ZephyrusColors\.(status\w+)Dark/', $block, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidStatusLabels(): array
    {
        $block = $this->between(file_get_contents(base_path(self::ANDROID_STATUS)), 'val label: String', 'val icon:');

        return $this->androidReturnMap($block);
    }

    /**
     * @return array<string, string>
     */
    private function androidStatusIcons(): array
    {
        $block = $this->between(file_get_contents(base_path(self::ANDROID_STATUS)), 'val icon: ImageVector', 'val severity:');
        preg_match_all('/\b(SUCCESS|WARNING|CRITICAL|INFO)\s+->\s+Icons\.Filled\.(\w+)/', $block, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [strtolower($match[1]) => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidStatusColorTokens(): array
    {
        $block = $this->between(file_get_contents(base_path(self::ANDROID_STATUS)), 'val color: Color', '/** A short label');
        preg_match_all('/\b(SUCCESS|WARNING|CRITICAL|INFO)\s+->\s+Z\.(status\w+)/', $block, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [strtolower($match[1]) => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function eddyCatalogTiers(): array
    {
        preg_match_all("/'tier'\s*=>\s*'(T\d)'/", file_get_contents(base_path(self::EDDY_ACTION_SERVICE)), $matches);

        return collect($matches[1] ?? [])->unique()->values()->all();
    }

    /**
     * @return array<string, string>
     */
    private function swiftReturnMap(string $block): array
    {
        preg_match_all('/case\s+\.(\w+):\s+return\s+"([^"]+)"/', $block, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function androidReturnMap(string $block): array
    {
        preg_match_all('/\b(SUCCESS|WARNING|CRITICAL|INFO)\s+->\s+"([^"]+)"/', $block, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [strtolower($match[1]) => $match[2]])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function parseInlineList(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $item): string => trim($item, " \t\n\r\0\x0B'\""))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalDocument(): array
    {
        return json_decode(file_get_contents(base_path(self::CANONICAL_CATALOG)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function between(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset, "Unable to find {$start}.");
        $startOffset += strlen($start);
        $endOffset = strpos($source, $end, $startOffset);
        $this->assertNotFalse($endOffset, "Unable to find {$end}.");

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
