<?php

namespace App\Models\Reference;

class CaseStatus extends BaseReference
{
    protected $table = 'prod.case_statuses';
    protected $primaryKey = 'status_id';
}
