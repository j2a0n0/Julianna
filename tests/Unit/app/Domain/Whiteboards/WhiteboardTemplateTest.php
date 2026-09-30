<?php

namespace Unit\app\Domain\Whiteboards;

use Illuminate\Support\Facades\Blade;
use Unit\TestCase;

class WhiteboardTemplateTest extends TestCase
{
    public function test_board_template_renders_without_a_version_from_the_layout(): void
    {
        config(['version' => 'whiteboard-view-regression']);

        $template = file_get_contents(APP_ROOT.'/app/Domain/Whiteboards/Templates/board.blade.php');
        $this->assertIsString($template);
        $template = preg_replace('/^@extends\(\$layout\)\R/', '', $template);
        $this->assertIsString($template);

        $this->assertIsString(Blade::render($template, [
            'board' => ['id' => 7, 'project_id' => 3, 'title' => 'Test board', 'revision' => 0],
            'revisions' => [],
            'canEditBoard' => true,
        ]));
    }

    public function test_board_editor_script_uses_runtime_version_without_header_composer_scope(): void
    {
        config(['version' => 'whiteboard-view-regression']);

        $template = file_get_contents(APP_ROOT.'/app/Domain/Whiteboards/Templates/board.blade.php');
        $this->assertIsString($template);
        $this->assertSame(1, preg_match('~^<script defer src="[^\n]*compiled-whiteboard[^\n]*</script>$~m', $template, $matches));

        $rendered = Blade::render($matches[0]);
        $this->assertStringContainsString('/dist/js/compiled-whiteboard.whiteboard-view-regression.min.js', $rendered);
    }
}
