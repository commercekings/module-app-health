<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Log;

use CommerceKings\AppHealth\Model\Log\MonologLineParser;
use PHPUnit\Framework\TestCase;

class MonologLineParserTest extends TestCase
{
    public function testMagentosFormatWithTracesContextAndLevels(): void
    {
        $data = "[2026-10-20T08:00:00.000000+00:00] main.INFO: Cron Job x is run [] []\n"
            . "[2026-10-20T08:00:01.000000+00:00] main.ERROR: Could not save {\"exception\":\"[object]"
            . " (Magento\\\\Framework\\\\Exception\\\\CouldNotSaveException(code: 0): Could not save at"
            . " \\/var\\/www\\/html\\/vendor\\/magento\\/module-quote\\/Model\\/QuoteRepository.php:84)\n[stacktrace]\n"
            . "#0 /var/www/html/x.php(1): f()\n[previous exception] [object] (PDOException(code: 23000): dup at"
            . " /var/www/html/y.php:2)\n\"} []\n"
            . "#continuation of an entry that started before\n"
            . "[2026-10-20T08:00:02.000000+00:00] payment.WARNING: Slow in /var/www/html/app/code/A/B.php:7 [] []\n"
            . "[2026-10-20T08:00:03.000000+00:00] main.CRITICAL: plain {\"a\":1} {\"b\":2}\n";
        $entries = (new MonologLineParser())->parse($data);
        $this->assertCount(3, $entries, 'INFO left out');
        [$error, $warning, $critical] = $entries;
        $this->assertSame(['ERROR', 'main', 'Could not save'], [$error->level, $error->channel, $error->message]);
        $this->assertSame(['Magento\\Framework\\Exception\\CouldNotSaveException', 'PDOException'], $error->classes);
        $this->assertSame(['/var/www/html/vendor/magento/module-quote/Model/QuoteRepository.php', 84],
            [$error->file, $error->line], 'from the JSON context, unescaped');
        $this->assertSame(1792483201, $error->time);
        $this->assertSame(['WARNING', 'payment', '/var/www/html/app/code/A/B.php', 7], [$warning->level,
            $warning->channel, $warning->file, $warning->line], 'a location in the message');
        $this->assertSame(['CRITICAL', 'plain', null], [$critical->level, $critical->message, $critical->file]);
    }
}
