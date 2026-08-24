<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\EmailService;

class SendWelcomeEmail
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly EmailService $emailService
    )
    {}

    /**
     * Handle the event.
     */
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;

        $this->emailService->send(
            to: $user->email,
            subject: 'Welcome to Blossom Glimmer',
            body: "
                <h1>Welcome, {$user->name}!</h1>
                <p>Your account has been created successfully.</p>
            "
        );
    }
}
