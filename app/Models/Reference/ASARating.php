<?php

namespace App\Models\Reference;

class ASARating extends BaseReference
{
    protected $table = 'prod.asa_ratings';
    protected $primaryKey = 'asa_id';

    protected $fillable = [
        'name',
        'code',
        'description',
        'created_by',
        'modified_by',
        'is_deleted'
    ];
}
