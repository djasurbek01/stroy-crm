<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PasswordReset;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Perm;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const LOGIN_WINDOW = 15 * 60;

    private const LOGIN_LIMIT_EMAIL = 5;

    private const LOGIN_LIMIT_IP = 30;

    private const RESET_TTL = 3600;

    public static function userOut(User $user): array
    {
        $org = Organization::find($user->organization_id);

        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
            'plan' => $org->plan,
            'can' => Perm::capsForUser($user),
        ];
    }

    /** Текст проблемы или null, если пароль подходит. */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Пароль не короче 8 символов';
        }
        if (! preg_match('/\p{L}/u', $password) || ! preg_match('/\d/', $password)) {
            return 'В пароле нужны и буквы, и цифры';
        }
        if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
            return 'Пароль не должен совпадать с почтой';
        }

        return null;
    }

    private function weak(string $password, string $email): void
    {
        if (self::passwordProblem($password, $email)) {
            ApiError::abort(422, 'weak_password');
        }
    }

    /** Вход в эту сессию браузера. Версия сессии запоминается: при её смене вход перестаёт работать. */
    private function signIn(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('token_version', $user->token_version);
    }

    public static function mailConfigured(): bool
    {
        return config('stroy.smtp_host') !== '' && config('stroy.smtp_from') !== '';
    }

    public function config()
    {
        return ['email_reset' => self::mailConfigured()];
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:200',
            'password' => 'required|string|min:8|max:64',
            'name' => 'required|string|min:1|max:200',
            'org_name' => 'required|string|min:1|max:200',
        ]);
        $ip = RateLimit::ip($request);
        RateLimit::check('register:ip', $ip, 10, 3600);
        $email = mb_strtolower($data['email']);
        $this->weak($data['password'], $email);
        if (User::where('email', $email)->exists()) {
            RateLimit::hit('register:ip', $ip);
            ApiError::abort(409, 'email_taken');
        }
        $org = Organization::create(['name' => $data['org_name']]);
        $user = User::create([
            'organization_id' => $org->id,
            'email' => $email,
            'password_hash' => Hash::make($data['password']),
            'name' => $data['name'],
            'role' => 'owner',
        ]);
        RateLimit::hit('register:ip', $ip);
        Audit::log($user, 'create', 'organization', $org->id, "Зарегистрирована организация «{$org->name}»");
        $this->signIn($request, $user->fresh());

        return response()->json(self::userOut($user), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $email = mb_strtolower($data['email']);
        $ip = RateLimit::ip($request);
        RateLimit::check('login:email', $email, self::LOGIN_LIMIT_EMAIL, self::LOGIN_WINDOW);
        RateLimit::check('login:ip', $ip, self::LOGIN_LIMIT_IP, self::LOGIN_WINDOW);
        $user = User::where('email', $email)->first();
        if ($user === null || ! Hash::check($data['password'], $user->password_hash)) {
            RateLimit::hit('login:email', $email);
            RateLimit::hit('login:ip', $ip);
            if ($user !== null) {
                Audit::log($user, 'login_failed', 'user', $user->id, 'Неудачная попытка входа', null, ['ip' => $ip]);
            }
            ApiError::abort(401, 'bad_credentials');
        }
        RateLimit::clear('login:email', $email);
        $this->signIn($request, $user);

        return self::userOut($user);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->noContent();
    }

    /** Завершает вход на всех устройствах, кроме этого. */
    public function logoutAll(Request $request)
    {
        $user = $this->user();
        $user->token_version++;
        $user->save();
        Audit::log($user, 'update', 'user', $user->id, 'Выход со всех устройств');
        $request->session()->put('token_version', $user->token_version);

        return $this->noContent();
    }

    public function me()
    {
        return self::userOut($this->user());
    }

    public function password(Request $request)
    {
        $data = $request->validate(['old_password' => 'required|string', 'new_password' => 'required|string|min:8|max:64']);
        $user = $this->user();
        $ip = RateLimit::ip($request);
        RateLimit::check('password:user', (string) $user->id, 5, self::LOGIN_WINDOW);
        if (! Hash::check($data['old_password'], $user->password_hash)) {
            RateLimit::hit('password:user', (string) $user->id);
            ApiError::abort(403, 'wrong_password');
        }
        $this->weak($data['new_password'], $user->email);
        $user->password_hash = Hash::make($data['new_password']);
        $user->token_version++;  // все прежние входы перестают работать
        $user->save();
        Audit::log($user, 'update', 'user', $user->id, 'Пароль изменён', null, ['ip' => $ip]);
        $request->session()->put('token_version', $user->token_version);

        return $this->noContent();
    }

    /** Ответ всегда один и тот же, чтобы по нему нельзя было узнать, есть ли такая почта. */
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = mb_strtolower($data['email']);
        $ip = RateLimit::ip($request);
        RateLimit::check('forgot:ip', $ip, 10, 3600);
        RateLimit::hit('forgot:ip', $ip);
        $user = User::where('email', $email)->first();
        if ($user !== null && self::mailConfigured() && RateLimit::count('forgot:email', $email, 3600) < 3) {
            RateLimit::hit('forgot:email', $email);
            $token = Str::random(43);
            PasswordReset::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds(self::RESET_TTL)]);
            $link = config('stroy.base_url')."/#/reset/$token";
            $body = "Здравствуйте, {$user->name}!\n\nЧтобы задать новый пароль, откройте ссылку (действует 1 час):\n$link\n\n"
                .'Если вы не просили сброс, просто проигнорируйте это письмо.';
            try {
                Mail::raw($body, fn ($m) => $m->to($user->email)->from(config('stroy.smtp_from'))->subject('Сброс пароля в Стройке'));
            } catch (\Throwable $e) { // пароль и адрес сервера в журнал не пишем
                Log::warning('mail send failed: '.class_basename($e));
            }
        }

        return response()->json(['sent' => true], 202);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|min:20|max:200', 'password' => 'required|string|min:8|max:64']);
        $ip = RateLimit::ip($request);
        RateLimit::check('reset:ip', $ip, 20, 3600);
        $row = PasswordReset::where('token_hash', hash('sha256', $data['token']))->first();
        if ($row === null || $row->used_at !== null || $row->expires_at->lt(now())) {
            RateLimit::hit('reset:ip', $ip);
            ApiError::abort(400, 'bad_reset_token');
        }
        $user = User::find($row->user_id);
        $this->weak($data['password'], $user->email);
        $user->password_hash = Hash::make($data['password']);
        $user->token_version++;
        $user->save();
        $row->used_at = now();
        $row->save();
        PasswordReset::where('user_id', $user->id)->where('id', '!=', $row->id)->delete();
        RateLimit::clear('login:email', $user->email);
        Audit::log($user, 'update', 'user', $user->id, 'Пароль сброшен по ссылке из письма', null, ['ip' => $ip]);
        $this->signIn($request, $user);

        return $this->noContent();
    }
}
