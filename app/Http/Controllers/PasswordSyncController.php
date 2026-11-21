<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// One-time owner tool: lets the signed-in admin set their own app password
// from the browser's saved login fill, so the app password matches the
// hosting (cPanel) password held in the same saved login. Self-disables
// permanently after one successful use (setting flag password_sync.done).
class PasswordSyncController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->role === 'admin', 404);
        abort_if(app(SettingsService::class)->get('password_sync.done'), 404);
    }

    public function show(Request $request)
    {
        $this->guard($request);
        $token = csrf_token();

        return response(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Password sync</title></head>
<body style="font-family: sans-serif; padding: 40px;">
<h1>Set admin password</h1>
<form method="POST" action="/admin/password-sync">
<input type="hidden" name="_token" value="{$token}">
<p><label>New Password<br>
<input type="password" name="password" autocomplete="new-password" style="padding:8px; width:320px;"></label></p>
<p><label>Confirm Password<br>
<input type="password" name="password_confirmation" autocomplete="new-password" style="padding:8px; width:320px;"></label></p>
<p><button type="submit" style="padding:10px 28px;">Save</button></p>
</form>
</body>
</html>
HTML);
    }

    public function update(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);
        app(SettingsService::class)->set('password_sync.done', '1');
        $user = $request->user();
        $user->password = Hash::make($data['password']);
        $user->save();

        return redirect('/dashboard');
    }
}
