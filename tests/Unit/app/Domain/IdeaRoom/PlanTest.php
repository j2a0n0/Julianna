<?php

declare(strict_types=1);

namespace Unit\app\Domain\IdeaRoom;

use InvalidArgumentException;
use Leantime\Domain\IdeaRoom\Support\Plan;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    public function test_partial_ai_patch_keeps_other_fields(): void
    {
        $plan = Plan::merge(Plan::empty(), ['outcome' => '  Useful outcome  ']);
        $plan = Plan::merge($plan, ['assumptions' => ['A constraint']]);

        self::assertSame('Useful outcome', $plan['outcome']);
        self::assertSame(['A constraint'], $plan['assumptions']);
        self::assertSame([], $plan['milestones']);
    }

    public function test_approval_requires_project_name_outcome_milestone_and_task(): void
    {
        $plan = Plan::replace([
            'projectName' => 'New project',
            'outcome' => 'Ship an initial version',
            'milestones' => [[
                'title' => 'First milestone',
                'description' => '',
                'tasks' => [['title' => 'First action', 'description' => '']],
            ]],
            'tasks' => [],
            'assumptions' => [],
            'openQuestions' => [],
        ]);

        Plan::assertReady($plan, true);
        self::assertTrue(true);
        $plan['projectName'] = '';
        Plan::assertReady($plan, false); // Existing-project flow does not require a new name.

        $this->expectException(InvalidArgumentException::class);
        Plan::assertReady($plan, true);
    }

    public function test_task_count_is_bounded_across_all_milestones(): void
    {
        $plan = Plan::empty();
        $plan['milestones'] = [
            ['title' => 'One', 'tasks' => array_fill(0, 60, ['title' => 'A'])],
            ['title' => 'Two', 'tasks' => array_fill(0, 41, ['title' => 'B'])],
        ];

        $this->expectException(InvalidArgumentException::class);
        Plan::merge(Plan::empty(), $plan);
    }

    public function test_blank_task_title_cannot_be_approved(): void
    {
        $plan = Plan::empty();
        $plan['outcome'] = 'Improve onboarding';
        $plan['milestones'] = [['title' => 'Research', 'tasks' => []]];
        $plan['tasks'] = [['title' => '']];

        $this->expectException(InvalidArgumentException::class);
        Plan::assertReady($plan, false);
    }
}
