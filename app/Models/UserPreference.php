<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserPreference extends Model
{
    protected $fillable=['user_id','language','theme','notifications'];
    protected $casts=['notifications'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
}
