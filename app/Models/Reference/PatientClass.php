<?php

namespace App\Models\Reference;

class PatientClass extends BaseReference
{
    protected $table = 'prod.patient_classes';
    protected $primaryKey = 'patient_class_id';
}
