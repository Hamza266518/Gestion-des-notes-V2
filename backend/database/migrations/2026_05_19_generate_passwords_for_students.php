<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    public function up(): void
    {
        $symbols = ['@', '#', '$', '&'];
        
        $users = DB::table('users as u')
            ->join('etudiants as e', 'e.user_id', '=', 'u.id')
            ->select('u.id', 'e.cin')
            ->whereNull('u.password_encrypted')
            ->get();

        foreach ($users as $user) {
            $password = strtoupper($user->cin) . $symbols[array_rand($symbols)];
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'password' => Hash::make($password),
                    'password_encrypted' => Crypt::encryptString($password),
                ]);
        }
    }

    public function down(): void
    {
        // Migration cannot be reversed
    }
};
