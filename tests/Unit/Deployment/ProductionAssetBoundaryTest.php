<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class ProductionAssetBoundaryTest extends TestCase
{
    public function test_deploy_removes_the_vite_hot_marker_before_restarting_apache(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/deploy.sh');
        $this->assertIsString($script);

        $publication = strpos($script, '"$RELEASE_ROOT/" /var/www/Zephyrus/');
        $hotMarkerRemoval = strpos($script, 'sudo rm -f /var/www/Zephyrus/public/hot');
        $apacheRestart = strpos($script, 'sudo systemctl restart apache2');

        $this->assertIsInt($publication);
        $this->assertIsInt($hotMarkerRemoval);
        $this->assertIsInt($apacheRestart);
        $this->assertGreaterThan($publication, $hotMarkerRemoval);
        $this->assertLessThan($apacheRestart, $hotMarkerRemoval);
    }

    public function test_deploy_rejects_live_html_that_references_a_development_vite_server(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/deploy.sh');
        $this->assertIsString($script);

        $this->assertStringContainsString(
            'https?://(localhost|127\\.0\\.0\\.1):[0-9]+/(@vite|@react-refresh|resources/)',
            $script,
        );
        $this->assertStringContainsString("grep -Eq '/build/assets/'", $script);
    }
}
