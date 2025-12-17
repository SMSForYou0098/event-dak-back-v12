<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Catrgoty_has_Field extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = ['category_id', 'custom_fields_id'];
    // protected $casts = [

    //     'custom_fields_id' => 'array', // Cast JSON to array
    // ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function customFields()
    {
        if (empty($this->custom_fields_id)) {
            return collect();
        }
        
        $fieldIds = explode(',', $this->custom_fields_id);
        // Filter out empty strings and convert to integers
        $fieldIds = array_filter(array_map('intval', array_filter($fieldIds, function($id) {
            return !empty(trim($id));
        })));
        
        if (empty($fieldIds)) {
            return collect();
        }
        
        return CustomField::whereIn('id', $fieldIds)->get();
    }

    public function customFieldsDataa()
    {
        if (empty($this->custom_fields_id)) {
            return $this->hasMany(CustomField::class, 'id', 'custom_fields_id')->whereRaw('1 = 0');
        }
        
        $fieldIds = explode(',', $this->custom_fields_id);
        // Filter out empty strings and convert to integers
        $fieldIds = array_filter(array_map('intval', array_filter($fieldIds, function($id) {
            return !empty(trim($id));
        })));
        
        if (empty($fieldIds)) {
            return $this->hasMany(CustomField::class, 'id', 'custom_fields_id')->whereRaw('1 = 0');
        }
        
        return $this->hasMany(CustomField::class, 'id', 'custom_fields_id')->whereIn('id', $fieldIds);
    }

    public function customFieldsDataaa()
    {
        return $this->hasMany(CustomField::class, 'id', 'category_id'); // Adjust 'CustomField' to match the actual model name
    }

}
