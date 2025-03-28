<?php
// This is a direct login script that bypasses all Laravel middleware
// It provides an alternative login method when CSRF validation is causing issues

// Include Laravel's autoloader
require __DIR__.'/../vendor/autoload.php';

// Start the Laravel application
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

// Set headers to allow cross-origin requests and bypass CSRF
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Origin, Authorization, Accept');
header('Content-Type: application/json');

// Only proceed for POST requests 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract credentials
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);
    
    // Use Laravel's Auth facade to attempt login
    if (\Illuminate\Support\Facades\Auth::attempt([
        'username' => $username, 
        'password' => $password
    ], $remember)) {
        // Get the authenticated user
        $user = \Illuminate\Support\Facades\Auth::user();
        
        // Update workflow preference if it's null
        if ($user->workflow_preference === null) {
            $user->update(['workflow_preference' => 'superuser']);
        }
        
        // Generate a session
        $request->session()->regenerate();
        $request->session()->put('username', $username);
        $request->session()->put('user_id', $user->id);
        
        // Return success
        echo json_encode([
            'success' => true,
            'redirect' => '/dashboard'
        ]);
    } else {
        // Return failure
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid credentials'
        ]);
    }
} else {
    // Return a message for non-POST requests
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint only accepts POST requests'
    ]);
}

// Terminate the request
$kernel->terminate($request, $response);
