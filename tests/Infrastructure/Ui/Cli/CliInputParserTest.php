<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Ui\Cli;

use Flexi\Infrastructure\Ui\Cli\CliInput;
use Flexi\Infrastructure\Ui\Cli\CliInputParser;
use Flexi\Infrastructure\Ui\Cli\CliType;
use PHPUnit\Framework\TestCase;

class CliInputParserTest extends TestCase
{
    public function testParseQueryCommand(): void
    {
        $input = ['console', '--query', 'list-modules', 'name=auth', 'version=1.0'];
        $result = CliInputParser::parse($input);

        $this->assertInstanceOf(CliInput::class, $result);
        $this->assertEquals('list-modules', $result->getCommandName());
        $this->assertEquals(['name' => 'auth', 'version' => '1.0'], $result->getArguments());
        $this->assertEquals(CliType::QUERY, $result->getType());
        $this->assertFalse($result->showHelp());
    }

    public function testParseQueryCommandWithShortFlag(): void
    {
        $input = ['console', '-q', 'list-modules', 'filter=enabled'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('list-modules', $result->getCommandName());
        $this->assertEquals(['filter' => 'enabled'], $result->getArguments());
        $this->assertEquals(CliType::QUERY, $result->getType());
    }

    public function testParseCommandType(): void
    {
        $input = ['console', '--command', 'install-module', 'name=auth', 'force=true'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('install-module', $result->getCommandName());
        $this->assertEquals(['name' => 'auth', 'force' => 'true'], $result->getArguments());
        $this->assertEquals(CliType::COMMAND, $result->getType());
    }

    public function testParseCommandTypeWithShortFlag(): void
    {
        $input = ['console', '-c', 'uninstall-module', 'name=auth'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('uninstall-module', $result->getCommandName());
        $this->assertEquals(['name' => 'auth'], $result->getArguments());
        $this->assertEquals(CliType::COMMAND, $result->getType());
    }

    public function testParseRegisteredModuleCommandAndHyphenatedModuleName(): void
    {
        $input = ['console', '--command', 'modules:activate', 'module_name=example-auth'];
        $result = CliInputParser::parse($input);

        $this->assertSame('modules:activate', $result->getCommandName());
        $this->assertSame(['module_name' => 'example-auth'], $result->getArguments());
        $this->assertSame(CliType::COMMAND, $result->getType());
    }

    public function testParseVersionAndHelpValues(): void
    {
        $input = ['console', '-c', 'modules:env-update', 'module_name=example-auth', 'version=1.2.3', '--help'];
        $result = CliInputParser::parse($input);

        $this->assertSame('modules:env-update', $result->getCommandName());
        $this->assertSame(['module_name' => 'example-auth', 'version' => '1.2.3'], $result->getArguments());
        $this->assertTrue($result->showHelp());
    }

    public function testParseEventType(): void
    {
        $input = ['console', '--event', 'module-installed', 'module=auth', 'timestamp=123456'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('module-installed', $result->getCommandName());
        $this->assertEquals(['module' => 'auth', 'timestamp' => '123456'], $result->getArguments());
        $this->assertEquals(CliType::EVENT, $result->getType());
    }

    public function testParseEventTypeWithShortFlag(): void
    {
        $input = ['console', '-e', 'cache-cleared', 'type=all'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('cache-cleared', $result->getCommandName());
        $this->assertEquals(['type' => 'all'], $result->getArguments());
        $this->assertEquals(CliType::EVENT, $result->getType());
    }

    public function testParseWithHelpFlag(): void
    {
        $input = ['console', '--query', 'list-modules', '--help'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('list-modules', $result->getCommandName());
        $this->assertEquals([], $result->getArguments());
        $this->assertEquals(CliType::QUERY, $result->getType());
        $this->assertTrue($result->showHelp());
    }

    public function testParseWithShortHelpFlag(): void
    {
        $input = ['console', '-c', 'install-module', 'name=auth', '-h'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('install-module', $result->getCommandName());
        $this->assertEquals(['name' => 'auth'], $result->getArguments());
        $this->assertEquals(CliType::COMMAND, $result->getType());
        $this->assertTrue($result->showHelp());
    }

    public function testParseWithNoArguments(): void
    {
        $input = ['console', '--query', 'status'];
        $result = CliInputParser::parse($input);

        $this->assertEquals('status', $result->getCommandName());
        $this->assertEquals([], $result->getArguments());
        $this->assertEquals(CliType::QUERY, $result->getType());
        $this->assertFalse($result->showHelp());
    }

    public function testParseWithArgumentsWithoutValues(): void
    {
        $input = ['console', '--command', 'clear-cache', 'force=true', 'verbose='];
        $result = CliInputParser::parse($input);

        $this->assertEquals('clear-cache', $result->getCommandName());
        $this->assertEquals(['force' => 'true', 'verbose' => ''], $result->getArguments());
        $this->assertEquals(CliType::COMMAND, $result->getType());
    }

    public function testParseInvalidFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid input format');

        $invalidInput = ['console', 'invalid-format'];
        CliInputParser::parse($invalidInput);
    }

    public function testParseInvalidFlagThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid input format');

        $invalidInput = ['console', '--invalid', 'command-name'];
        CliInputParser::parse($invalidInput);
    }

    public function testParseEmptyInputThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid input format');

        $emptyInput = ['console'];
        CliInputParser::parse($emptyInput);
    }

    public function testParseWithComplexArguments(): void
    {
        $input = [
            'console',
            '--command',
            'install-module',
            'name=authentication',
            'version=2.1.0',
            'environment=production',
            'force=true',
            '--help'
        ];

        $result = CliInputParser::parse($input);

        $this->assertEquals('install-module', $result->getCommandName());
        $this->assertEquals([
            'name' => 'authentication',
            'version' => '2.1.0',
            'environment' => 'production',
            'force' => 'true'
        ], $result->getArguments());
        $this->assertEquals(CliType::COMMAND, $result->getType());
        $this->assertTrue($result->showHelp());
    }
}
