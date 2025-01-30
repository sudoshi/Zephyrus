<?php

namespace App\Models\Reference;

class CancellationReason extends BaseReference
{
    protected $table = 'prod.cancellation_reasons';
    protected $primaryKey = 'cancellation_id';
}
