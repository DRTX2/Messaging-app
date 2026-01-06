<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login (Request $request){
        $credentials= $request->validate([
            'email' => ['required', 'email'],
            'password'=>['required'],
        ]);

        if(!Auth::attempt($credentials)){
            return response()->json(['message'=>'Invalid credentials'], 401);
        }

        $token= $request->user()->createToken('api-token')->plainTextToken;
        return response()->json(['token'=>$token, 'user'=>$request->user()], 200);
    }

    public function logout (Request $request){

        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logged out'], 200);
    }
}
