<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Message;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Api\Data\TeamMessageTable;
use CommerceKings\AppHealth\Api\TeamMessageRendererInterface;
use CommerceKings\AppHealth\Model\Message\PlainTextRenderer;
use CommerceKings\AppHealth\Model\Message\RendererPool;
use CommerceKings\AppHealth\Model\Message\SlackRenderer;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use PHPUnit\Framework\TestCase;

class RenderersTest extends TestCase
{
    use Pool;

    private const NOW = 1792483200; // 2026-10-20 08:00 UTC
    private const MODULE = __DIR__ . '/../../../..';

    private function message(): TeamMessageContent
    {
        return new TeamMessageContent('Shop · daily summary · 20 Oct', TeamMessageContent::SUMMARY, [
            new TeamMessageSection(['Cron: stopped', 'Indexers: failed earlier, OK now'], 'Needs attention', true,
                true),
            new TeamMessageSection(['…and 2 more'], null, false, false, false, [new TeamMessageTable(
                'Containers · web-1', ['NAME', 'CPU'], [['php-fpm', '1.5%'],
                ['a-container-name-longer-than-the-cut', '12.0%']])]),
            new TeamMessageSection(['New errors this hour'], null),
            new TeamMessageSection([]),
        ], ['Restart cron.']);
    }

    public function testSlackIsMrkdwnWithBoldTitlesBulletsAndCodeBlockTables(): void
    {
        $this->assertSame("*Shop · daily summary · 20 Oct*\n\n"
            . "*Needs attention*\n• Cron: stopped\n• Indexers: failed earlier, OK now\n\n"
            . "*Containers · web-1*\n```\n"
            . "NAME                            CPU\n"
            . "php-fpm                        1.5%\n"
            . "a-container-name-longer-than  12.0%\n```\n…and 2 more\n\n"
            . "New errors this hour\n\n"
            . 'What to do: Restart cron.', (new SlackRenderer())->render($this->message()));
    }

    public function testPlainTextHasNoMarkupDashListsAndAnAlignedTable(): void
    {
        $this->assertSame("Shop · daily summary · 20 Oct\n\n"
            . "Needs attention\n- Cron: stopped\n- Indexers: failed earlier, OK now\n\n"
            . "Containers · web-1\n"
            . "NAME                            CPU\n"
            . "php-fpm                        1.5%\n"
            . "a-container-name-longer-than  12.0%\n…and 2 more\n\n"
            . "New errors this hour\n\n"
            . 'What to do: Restart cron.', (new PlainTextRenderer())->render($this->message()));
    }

    public function testEveryMessageInPlainTextIsFreeOfSlackMarkup(): void
    {
        $messages = new Messages($this->pool(['cron' => 'Cron', 'system_log' => 'system.log']), new Clock(self::NOW));
        $target = new Target(0, 1, 'sms', '+12025550123', 'Acme Shop', 'UTC', true, 'shop.example.com');
        $containers = $messages->containers(['web-1' => ['generated_at' => self::NOW, 'checks' => [['code' => 'c',
            'label' => 'Container php', 'metrics' => ['cpu_percent' => 2.0]]]]], self::NOW, 900);
        $renderer = new PlainTextRenderer();
        $texts = [
            $renderer->render($messages->alert($target, 'cron', ['status' => 'fail', 'detail' => 'stopped',
                'causes' => [['a', 'no job ran', 'Restart cron.']]], 'problem')),
            $renderer->render($messages->notices($target, 'system_log', ["Error: x (08:00)\nWhere: a.php:1",
                'Check: Look at the log.'])),
            $renderer->render($messages->status($target, ['Cron'], [], 3, [['Timeout', 3, true]], 1)),
            $renderer->render($messages->summary($target, ['cron' => ['status' => 'fail', 'detail' => 'stopped']], [],
                self::NOW, [], ['Card number in system.log'], $containers)),
            $renderer->render($messages->test($target)),
        ];
        foreach ($texts as $text) {
            $this->assertDoesNotMatchRegularExpression('/[*`•]/u', $text, $text);
        }
        $this->assertSame("Problem: Cron · Acme Shop · shop.example.com\n\nNo job ran.\n\nWhat to do: Restart cron.",
            $texts[0]);
        $this->assertSame("Hourly status · Acme Shop · shop.example.com\n\n1 problem: Cron\n\n"
            . "New errors this hour: 3\n- Timeout (3×, new)\n- …and 1 more", $texts[2]);
        $this->assertStringContainsString("Needs attention\n- Cron: stopped\n\nSecurity\n- Card number in system.log"
            . "\n\nContainers · web-1\nNAME   CPU  MEM  MEM%  RESTARTS\nphp   2.0%    -     -         -", $texts[3]);
    }

    public function testTheModuleRendersSlackAsSlackAndAnyOtherChannelAsPlainText(): void
    {
        $di = simplexml_load_file(self::MODULE . '/etc/di.xml');
        $items = $di->xpath('//type[@name="CommerceKings\AppHealth\Model\Message\RendererPool"]'
            . '/arguments/argument[@name="renderers"]/item');
        $this->assertSame(['slack' => SlackRenderer::class], array_combine(
            array_map(fn ($item) => (string)$item['name'], $items), array_map(fn ($item) => (string)$item, $items)));
        $pool = new RendererPool(new PlainTextRenderer(), ['slack' => new SlackRenderer()]);
        $message = new TeamMessageContent('Test alert · Shop', TeamMessageContent::TEST,
            [new TeamMessageSection(['A', 'B'], 'List', true, true)]);
        $this->assertSame("*Test alert · Shop*\n\n*List*\n• A\n• B", $pool->render('slack', $message));
        $this->assertSame("Test alert · Shop\n\nList\n- A\n- B", $pool->render('sms', $message));
        $this->assertSame("Test alert · Shop\n\nList\n- A\n- B", $pool->render('', $message));
    }

    /** docs/examples/teams-renderer: what a Teams module adds - its di.xml item and its renderer. */
    public function testATeamsModuleAddsItsRendererForItsChannelByDiXml(): void
    {
        $example = self::MODULE . '/docs/examples/teams-renderer';
        require_once $example . '/Model/AppHealthRenderer.php';
        $di = simplexml_load_file($example . '/etc/di.xml');
        $items = [];
        foreach ($di->xpath('//type[@name="' . RendererPool::class . '"]/arguments/argument[@name="renderers"]/item')
            as $item) {
            $this->assertSame('object', (string)$item->attributes('xsi', true)['type']);
            $items[(string)$item['name']] = new ((string)$item)();
        }
        $this->assertSame(['teams'], array_keys($items));
        $this->assertInstanceOf(TeamMessageRendererInterface::class, $items['teams']);
        // di.xml merges the arguments of every module: App health's slack, the Teams module's teams
        $pool = new RendererPool(new PlainTextRenderer(), ['slack' => new SlackRenderer()] + $items);
        $message = new TeamMessageContent('Problem: Cron stopped · Shop', TeamMessageContent::PROBLEM,
            [new TeamMessageSection(['No job has run since 10:39.'])], ['Restart cron.']);
        $this->assertSame("**Problem: Cron stopped · Shop**\n\nNo job has run since 10:39.\n\n"
            . '**What to do:** Restart cron.', $pool->render('teams', $message));
        $this->assertStringStartsWith('*Problem: Cron stopped · Shop*', $pool->render('slack', $message));
    }

    /** Escaping is the provider's (module-notification-slack escapes & and <): the renderer passes data on as is. */
    public function testRenderersLeaveEscapingToTheProvider(): void
    {
        $message = new TeamMessageContent('New error in system.log · A&B <!channel>', TeamMessageContent::NOTICE,
            [new TeamMessageSection(['Error: <https://evil.test|click> &amp; more'])], ['Ask <@U0123ABCD>.']);
        $this->assertSame("*New error in system.log · A&B <!channel>*\n\nError: <https://evil.test|click> &amp; more"
            . "\n\nWhat to do: Ask <@U0123ABCD>.", (new SlackRenderer())->render($message),
            'unescaped here: escaped once, by the provider, not twice');
        $this->assertStringContainsString('MUST escape & and <', (string)(new \ReflectionClass(
            TeamMessageRendererInterface::class))->getDocComment(), 'the contract says so');
    }

    public function testARendererThatIsNoneOrAKeyThatIsNoCodeIsRefused(): void
    {
        foreach ([['teams' => new \stdClass()], ['Teams!' => new PlainTextRenderer()]] as $renderers) {
            try {
                new RendererPool(new PlainTextRenderer(), $renderers);
                $this->fail('accepted ' . json_encode(array_keys($renderers)));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(RendererPool::class, $e->getMessage());
            }
        }
    }
}
