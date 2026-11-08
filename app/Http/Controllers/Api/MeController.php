<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
class MeController extends Controller
{
    public function show(Request $r){return response()->json(['user'=>$r->user()]);}
    public function update(Request $r){
        $data=$r->validate(['name'=>'sometimes|required|string|max:120','email'=>'sometimes|required|email|max:190|unique:users,email,'.$r->user()->id,'password'=>'sometimes|required|string|min:8|confirmed']);
        if(isset($data['password'])) $data['password']=Hash::make($data['password']);
        $r->user()->update($data); return response()->json(['user'=>$r->user()->fresh()]);
    }
    public function destroy(Request $r){$r->user()->delete(); return response()->json(['message'=>'Compte et données associées supprimés.']);}
}
