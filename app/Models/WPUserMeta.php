<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WPUserMeta extends Model
{
    use HasFactory;

    protected $table = "wp_usermeta";

    protected $fillable = [
        'umeta_id',
        'user_id',
        'meta_key',
        'meta_value'
    ];

    public $timestamps = false;

    public function getUserMeta($userId) {
        $userMeta = WPUserMeta::where('user_id', '=', $userId)->get();
        $metaData = [];

        foreach ($userMeta as $meta) {
            $metaData[$meta->meta_key] = $meta->meta_value;
        }

        return $metaData;
    }
}
