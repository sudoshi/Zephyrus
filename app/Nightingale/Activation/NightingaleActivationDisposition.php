<?php

namespace App\Nightingale\Activation;

/**
 * Route-free result of evaluating the five independent activation gates.
 *
 * ContinueToOperationSpecificReleaseEvaluation is deliberately weaker than
 * access, authorization, disclosure, pilot approval, or production readiness.
 */
enum NightingaleActivationDisposition: string
{
    case Hold = 'hold';
    case ContinueToOperationSpecificReleaseEvaluation = 'continue_to_operation_specific_release_evaluation';
}
