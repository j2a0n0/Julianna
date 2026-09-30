<?php

declare(strict_types=1);

namespace Unit\app\Domain\Blueprints;

use Illuminate\Support\Facades\Blade;
use Unit\TestCase;

final class SwotElementTemplateTest extends TestCase
{
    public function test_existing_swot_item_with_empty_relationship_renders_without_500(): void
    {
        $template = file_get_contents(APP_ROOT.'/app/Domain/Blueprints/Templates/element.blade.php');
        self::assertIsString($template);
        $html = Blade::render($template, [
            'elementName' => 'swot_strengths',
            'canvasTypes' => ['swot_strengths' => ['title' => 'Strengths']],
            'canvasItems' => [[
                'id' => 7, 'box' => 'swot_strengths', 'description' => 'Fast setup',
                'status' => '', 'relates' => '', 'conclusion' => '',
                'author' => 1, 'authorFirstname' => '',
                'milestoneHeadline' => '', 'milestoneId' => '',
            ]],
            'filter' => ['status' => 'all', 'relates' => 'all'],
            'statusLabels' => [],
            'relatesLabels' => ['relates_none' => ['dropdown' => 'default', 'title' => 'None', 'active' => true]],
            'login' => SwotTemplateLogin::class,
            'roles' => SwotTemplateRoles::class,
            'users' => [],
            'canvasSlug' => 'swot',
        ]);
        self::assertStringContainsString('Fast setup', $html);
        self::assertStringContainsString('None', $html);
    }
}

final class SwotTemplateLogin
{
    public static function userIsAtLeast(int $role): bool
    {
        return false;
    }
}

final class SwotTemplateRoles
{
    public static int $editor = 20;
}
