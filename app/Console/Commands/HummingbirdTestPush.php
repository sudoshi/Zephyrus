<?php

namespace App\Console\Commands;

use App\Contracts\PushNotifier;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Send a test push to a user's registered Hummingbird devices. Uses the bound PushNotifier
 * (real APNs when configured, else the log stub), so it's the operational lever to verify the
 * device-registry → notifier → APNs path end-to-end.
 *
 *   php artisan hummingbird:test-push demo
 *   php artisan hummingbird:test-push demo --title="Bed placement needed" --body="ICU placement is waiting." --tab=foryou
 */
class HummingbirdTestPush extends Command
{
    protected $signature = 'hummingbird:test-push {username}
        {--title=Hummingbird} {--body=You have an item that needs attention.} {--tab=foryou}';

    protected $description = "Send a test push to a user's registered devices (PHI-free).";

    public function handle(PushNotifier $push): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error("No user with username '{$this->argument('username')}'.");

            return self::FAILURE;
        }

        $count = $push->sendToUser(
            $user,
            (string) $this->option('title'),
            (string) $this->option('body'),
            ['tab' => (string) $this->option('tab')],
        );

        $this->info("Dispatched to {$count} device(s) for {$user->username} via ".class_basename($push).'.');

        return self::SUCCESS;
    }
}
