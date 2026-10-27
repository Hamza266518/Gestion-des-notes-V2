<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
foreach(User::where('role','formateur')->get() as $u){
  $p = User::generateStrongPassword();
  $u->password = Hash::make($p);
  $u->password_encrypted = Crypt::encryptString($p);
  $u->password_original_encrypted = Crypt::encryptString($p);
  $u->save();
  echo $u->username.':'.$p.PHP_EOL;
}
