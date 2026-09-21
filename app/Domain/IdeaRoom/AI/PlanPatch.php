<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\AI;

use InvalidArgumentException;

final class PlanPatch
{
    private const FIELDS = ['projectName', 'outcome', 'milestones', 'tasks', 'assumptions', 'openQuestions'];

    private const MAX_MILESTONES = 30;

    private const MAX_TASKS = 100;

    private const MAX_NOTES = 50;

    /**
     * Validate a partial replacement of the current plan. Missing fields stay unchanged.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function validate(array $patch): array
    {
        $clean = [];

        foreach ($patch as $key => $value) {
            if (! in_array($key, self::FIELDS, true)) {
                throw new InvalidArgumentException('The plan patch contains an unknown field.');
            }

            if ($key === 'projectName') {
                $clean[$key] = self::cleanString($value, 255);
            } elseif ($key === 'outcome') {
                $clean[$key] = self::cleanString($value, 5000);
            } elseif (in_array($key, ['assumptions', 'openQuestions'], true)) {
                $clean[$key] = self::cleanStringList($value);
            } elseif ($key === 'tasks') {
                $clean[$key] = self::cleanTaskList($value);
            } elseif ($key === 'milestones') {
                $clean[$key] = self::cleanMilestoneList($value);
            }
        }

        return $clean;
    }

    private static function cleanString(mixed $value, int $maxLength): string
    {
        if (! is_string($value) || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('The plan patch contains an invalid text value.');
        }

        return trim($value);
    }

    /** @return array<int, string> */
    private static function cleanStringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_NOTES) {
            throw new InvalidArgumentException('The plan patch contains an invalid list.');
        }

        $clean = [];
        foreach ($value as $item) {
            $clean[] = self::cleanString($item, 2000);
        }

        return $clean;
    }

    /** @return array<int, array{title: string, description: string}> */
    private static function cleanTaskList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_TASKS) {
            throw new InvalidArgumentException('The plan patch contains an invalid task list.');
        }

        $clean = [];
        foreach ($value as $task) {
            if (! is_array($task) || ! isset($task['title']) || array_diff(array_keys($task), ['title', 'description']) !== []) {
                throw new InvalidArgumentException('The plan patch contains an invalid task.');
            }

            $clean[] = [
                'title' => self::cleanString($task['title'], 255),
                'description' => self::cleanString($task['description'] ?? '', 5000),
            ];
        }

        return $clean;
    }

    /** @return array<int, array{title: string, description: string, tasks: array<int, array{title: string, description: string}>}> */
    private static function cleanMilestoneList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_MILESTONES) {
            throw new InvalidArgumentException('The plan patch contains an invalid milestone list.');
        }

        $clean = [];
        foreach ($value as $milestone) {
            if (! is_array($milestone) || ! isset($milestone['title']) || array_diff(array_keys($milestone), ['title', 'description', 'tasks']) !== []) {
                throw new InvalidArgumentException('The plan patch contains an invalid milestone.');
            }

            $clean[] = [
                'title' => self::cleanString($milestone['title'], 255),
                'description' => self::cleanString($milestone['description'] ?? '', 5000),
                'tasks' => self::cleanTaskList($milestone['tasks'] ?? []),
            ];
        }

        return $clean;
    }
}
