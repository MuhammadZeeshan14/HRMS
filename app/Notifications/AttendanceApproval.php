<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AttendanceApproval extends Notification
{
    public function __construct(public int $requestId, public string $employeeName) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return ['correction_id' => $this->requestId, 'message' => $this->employeeName.' submitted an attendance request.'];
    }
}
