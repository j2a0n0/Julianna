<?php

namespace Leantime\Domain\Queue\Workers;

use Illuminate\Support\Facades\Log;
use Leantime\Domain\Queue\Repositories\Queue;
use Throwable;

class DefaultWorker
{
    public function __construct(
        private Queue $queue
    ) {}

    public function handleQueue($messages): bool
    {
        $allSucceeded = true;
        foreach ($messages as $message) {
            try {
                $payload = safe_unserialize($message['message']);
                $subjectClass = $message['subject'];

                $jobClass = app()->make($subjectClass);

                $result = $jobClass->handle($payload);

                if ($result) {
                    $this->queue->deleteMessageInQueue($message['msghash']);
                } else {
                    Log::error('Default queue job returned an unsuccessful result.');
                    $allSucceeded = false;
                }
            } catch (Throwable) {
                // Job payloads may contain sensitive data; do not log the exception object.
                Log::error('Default queue job failed.');
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }
}
