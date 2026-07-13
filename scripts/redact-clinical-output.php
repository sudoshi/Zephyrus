<?php

declare(strict_types=1);

use App\Security\ClinicalPayloads\ClinicalContentGuard;

require dirname(__DIR__).'/vendor/autoload.php';

$guard = new ClinicalContentGuard;
$content = stream_get_contents(STDIN);
fwrite(STDOUT, $guard->redactString($content));
