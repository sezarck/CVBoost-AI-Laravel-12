<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller
{
    public function register(Request $request) {
        $data=$request->validate(['name'=>'required|string|max:120','email'=>'required|email:rfc,dns|max:190|unique:users,email','password'=>'required|string|min:8|confirmed']);
        $user=User::create($data);
        return response()->json(['message'=>'Compte créé.','user'=>$user,'token'=>$user->createToken('cvboost-web')->plainTextToken],201);
    }
    public function login(Request $request) {
        $data=$request->validate(['email'=>'required|email','password'=>'required|string']);
        $user=User::where('email',$data['email'])->first();
        if(!$user || !Hash::check($data['password'],$user->password)) throw ValidationException::withMessages(['email'=>['Identifiants incorrects.']]);
        return response()->json(['user'=>$user,'token'=>$user->createToken('cvboost-web')->plainTextToken]);
    }
    public function logout(Request $request) { $request->user()->currentAccessToken()?->delete(); return response()->json(['message'=>'Déconnexion réussie.']); }
    public function forgotPassword(Request $request) {
        $request->validate(['email'=>'required|email']);
        $status=Password::sendResetLink($request->only('email'));
        return response()->json(['message'=>__($status)], $status===Password::RESET_LINK_SENT?200:422);
    }
}
