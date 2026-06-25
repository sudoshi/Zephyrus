<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'phone' => 'nullable|string|max:20',
        ]);

        // Auto-generate username from email (part before @)
        $baseUsername = strtolower(explode('@', $request->email)[0]);
        // Remove non-alphanumeric characters except dashes and underscores
        $baseUsername = preg_replace('/[^a-z0-9_-]/', '', $baseUsername);
        $username = $baseUsername;

        // Ensure uniqueness by appending a number if needed
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername.$counter;
            $counter++;
        }

        // Generate a 12-character temporary password (excluding I, l, O, 0)
        $tempPassword = $this->generateTempPassword(12);

        $user = User::create([
            'name' => $request->name,
            'username' => $username,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
            'role' => 'user',
            'is_active' => true,
        ]);

        // Send temporary password via Resend API
        $this->sendTempPasswordEmail($user, $tempPassword, $username);

        return redirect()->route('login')->with(
            'status',
            'Account created! Check your email for your temporary password and username.'
        );
    }

    /**
     * Generate a temporary password excluding ambiguous characters (I, l, O, 0).
     */
    private function generateTempPassword(int $length): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ123456789!@#$%^&*';
        $password = '';
        $charsLength = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsLength - 1)];
        }

        return $password;
    }

    /**
     * Send temporary password email via Resend API.
     */
    private function sendTempPasswordEmail(User $user, string $tempPassword, string $username): void
    {
        try {
            $response = Http::withToken(config('services.resend.key'))
                ->post('https://api.resend.com/emails', [
                    'from' => 'Zephyrus <noreply@acumenus.net>',
                    'to' => [$user->email],
                    'subject' => 'Your Zephyrus Account Has Been Created',
                    'html' => $this->buildEmailHtml($user->name, $username, $tempPassword),
                ]);

            if ($response->failed()) {
                Log::error('Failed to send temp password email via Resend', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception sending temp password email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the HTML email body.
     */
    private function buildEmailHtml(string $name, string $username, string $tempPassword): string
    {
        return "
            <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;\">
                <h2 style=\"color: #4f46e5;\">Welcome to Zephyrus</h2>
                <p>Hello {$name},</p>
                <p>Your account has been created successfully. Here are your login credentials:</p>
                <div style=\"background-color: #f3f4f6; border-radius: 8px; padding: 16px; margin: 16px 0;\">
                    <p style=\"margin: 4px 0;\"><strong>Username:</strong> {$username}</p>
                    <p style=\"margin: 4px 0;\"><strong>Temporary Password:</strong> {$tempPassword}</p>
                </div>
                <p>You will be required to change your password upon first login.</p>
                <p style=\"color: #6b7280; font-size: 14px;\">If you did not request this account, please ignore this email.</p>
            </div>
        ";
    }
}
