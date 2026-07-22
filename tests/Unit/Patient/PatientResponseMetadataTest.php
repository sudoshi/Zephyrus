<?php

namespace Tests\Unit\Patient;

use App\Http\Middleware\AssignRequestIdentity;
use App\Services\Patient\PatientResponseMetadata;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PatientResponseMetadataTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'hummingbird-patient' => require dirname(__DIR__, 3).'/config/hummingbird-patient.php',
            'hummingbird-patient-content' => require dirname(__DIR__, 3).'/config/hummingbird-patient-content.php',
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_metadata_declares_the_configured_state_vocabulary_version(): void
    {
        $request = Request::create('/api/patient/v1/me');
        $request->attributes->set(AssignRequestIdentity::ATTRIBUTE, 'req-state-vocabulary');

        $metadata = (new PatientResponseMetadata)->forRequest($request);

        $this->assertSame('patient-state-vocabulary.v1-draft', $metadata['state_vocabulary_version']);
        $this->assertSame('req-state-vocabulary', $metadata['request_id']);
    }
}
