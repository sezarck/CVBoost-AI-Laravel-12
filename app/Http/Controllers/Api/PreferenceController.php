<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class PreferenceController extends Controller
{
    public function show(Request $r){$p=$r->user()->preference()->firstOrCreate([],['language'=>'fr','theme'=>'light','notifications'=>[]]);return response()->json(['preferences'=>$p]);}
    public function update(Request $r){$data=$r->validate(['language'=>'sometimes|string|in:fr,en','theme'=>'sometimes|string|in:light,dark,system','notifications'=>'sometimes|array']);$p=$r->user()->preference()->firstOrCreate([],['language'=>'fr','theme'=>'light','notifications'=>[]]);$p->update($data);return response()->json(['preferences'=>$p->fresh()]);}
}
