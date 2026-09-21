<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Support;

use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\AI\PlanPatch;

final class Plan
{
    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return [
            'projectName' => '',
            'outcome' => '',
            'milestones' => [],
            'tasks' => [],
            'assumptions' => [],
            'openQuestions' => [],
        ];
    }

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $patch): array
    {
        $plan = array_replace($current, PlanPatch::validate($patch));
        $taskCount = count($plan['tasks'] ?? []);
        foreach (($plan['milestones'] ?? []) as $milestone) {
            $taskCount += count($milestone['tasks'] ?? []);
        }
        if ($taskCount > 100) {
            throw new InvalidArgumentException('A plan can contain at most 100 tasks.');
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function replace(array $plan): array
    {
        if (array_diff(array_keys(self::empty()), array_keys($plan)) !== []) {
            throw new InvalidArgumentException('The plan is missing required fields.');
        }

        return self::merge(self::empty(), $plan);
    }

    /** @param array<string, mixed> $plan */
    public static function assertReady(array $plan, bool $needsProject): void
    {
        $plan = self::replace($plan);
        if ($needsProject && $plan['projectName'] === '') {
            throw new InvalidArgumentException('Add a project name before approval.');
        }
        if ($plan['outcome'] === '') {
            throw new InvalidArgumentException('Add an outcome before approval.');
        }
        if ($plan['milestones'] === []) {
            throw new InvalidArgumentException('Add at least one milestone before approval.');
        }

        $taskCount = count($plan['tasks']);
        foreach ($plan['milestones'] as $milestone) {
            if ($milestone['title'] === '') {
                throw new InvalidArgumentException('Every milestone needs a title.');
            }
            $taskCount += count($milestone['tasks']);
            foreach ($milestone['tasks'] as $task) {
                if ($task['title'] === '') {
                    throw new InvalidArgumentException('Every task needs a title.');
                }
            }
        }
        foreach ($plan['tasks'] as $task) {
            if ($task['title'] === '') {
                throw new InvalidArgumentException('Every task needs a title.');
            }
        }
        if ($taskCount === 0) {
            throw new InvalidArgumentException('Add at least one task before approval.');
        }
    }
}
