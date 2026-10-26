<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\ErrorHandling;

use Flexi\Infrastructure\ErrorHandling\ErrorHandlerCall;
use PHPUnit\Framework\TestCase;

class ErrorHandlerCallTest extends TestCase
{
    public function testConvertsReportableErrorsToExceptions(): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('reported warning');

        ErrorHandlerCall::run(static function (): void {
            trigger_error('reported warning', E_USER_WARNING);
        });
    }

    public function testRespectsSuppressedErrors(): void
    {
        $result = ErrorHandlerCall::run(static function (): string {
            @trigger_error('suppressed deprecation', E_USER_DEPRECATED);

            return 'completed';
        });

        $this->assertSame('completed', $result);
    }
}
