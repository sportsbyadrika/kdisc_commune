<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notify\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testHtmlToTextKeepsLinksAndDropsDecoration(): void
    {
        $html = '<html><head><style>p{}</style></head><body><!--notext--><div>preheader</div><!--/notext-->'
            . '<h1>Welcome</h1><p>Click <a href="https://x.test/password/set/abc?x=1&amp;y=2">Set your password</a>.</p><ul><li>One</li></ul></body></html>';
        $text = Mailer::htmlToText($html);
        self::assertStringNotContainsString('preheader', $text);
        self::assertStringNotContainsString('p{}', $text);
        self::assertStringContainsString('Welcome', $text);
        self::assertStringContainsString('Set your password ( https://x.test/password/set/abc?x=1&y=2 )', $text);
        self::assertStringContainsString('• One', $text);
    }
}
