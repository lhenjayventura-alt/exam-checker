<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('exam:create-admin {email} {name}', function (string $email, string $name) {
    $password = $this->secret('Password (min. 8 characters)');
    $validator = Validator::make(
        ['email' => $email, 'name' => $name, 'password' => $password],
        ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'max:255'], 'password' => ['required', Password::defaults()]],
    );
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    (new RoleSeeder)->run();
    $user = User::create(['email' => $email, 'name' => $name, 'password' => $password, 'is_active' => true]);
    $user->roles()->attach(Role::where('slug', Role::ADMIN)->value('id'));
    AuditLog::record('user.created', $user, [], ['email' => $email, 'roles' => [Role::ADMIN], 'via' => 'console']);
    $this->info("Administrator {$email} created.");

    return 0;
})->purpose('Create the first administrator account');
