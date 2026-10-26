<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\DependencyInjection;

use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\EventInterface;
use Flexi\Contracts\Interfaces\EventListenerInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;
use Flexi\Infrastructure\DependencyInjection\HandlerDefinitionValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HandlerDefinitionValidatorTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/flexi-preflight-' . uniqid('', true);
        mkdir($this->tempDirectory, 0777, true);
        PreflightRequestHandler::$constructed = false;
        PreflightControllerFactory::$called = false;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tempDirectory);
    }

    public function testChecksHandlerContractsWithoutCallingConstructors(): void
    {
        $files = [
            'routes' => [$this->write('routes.json', ['routes' => [[
                'controller' => PreflightRequestHandler::class,
                'middlewares' => ['preflight.middleware'],
            ]]])],
            'listeners' => [$this->write('listeners.json', [[
                'event' => 'test.event',
                'listeners' => [PreflightEventListener::class],
            ]])],
            'commands' => [$this->write('commands.json', ['handlers' => [[
                'handler' => PreflightCommandHandler::class,
            ]]])],
            'queries' => [],
        ];
        $services = [
            'preflight.middleware' => ['class' => ['name' => PreflightMiddleware::class]],
        ];

        (new HandlerDefinitionValidator())->validate($files, $services);

        $this->assertFalse(PreflightRequestHandler::$constructed);
    }

    public function testRejectsHandlerWithIncompatibleContract(): void
    {
        $file = $this->write('routes.json', ['routes' => [[
            'controller' => PreflightCommandHandler::class,
        ]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(RequestHandlerInterface::class);

        (new HandlerDefinitionValidator())->validate(['routes' => [$file]], []);
    }

    public function testRejectsMissingHandlerClass(): void
    {
        $file = $this->write('commands.json', ['handlers' => [[
            'handler' => 'Flexi\\Missing\\CommandHandler',
        ]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot resolve to a loadable class');

        (new HandlerDefinitionValidator())->validate(['commands' => [$file]], []);
    }

    public function testResolvesAliasesAndTypedFactoriesWithoutCallingFactory(): void
    {
        $file = $this->write('routes.json', ['routes' => [[
            'controller' => 'preflight.controller.alias',
        ]]]);
        $services = [
            'preflight.controller.alias' => 'preflight.controller',
            'preflight.controller' => ['factory' => [
                'class' => PreflightControllerFactory::class,
                'method' => 'create',
            ]],
        ];

        (new HandlerDefinitionValidator())->validate(['routes' => [$file]], $services);

        $this->assertFalse(PreflightControllerFactory::$called);
    }

    private function write(string $name, array $document): string
    {
        $file = $this->tempDirectory . '/' . $name;
        file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));

        return $file;
    }
}

class PreflightRequestHandler implements RequestHandlerInterface
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new \LogicException('Not called during static validation.');
    }
}

class PreflightMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new \LogicException('Not called during static validation.');
    }
}

class PreflightEventListener implements EventListenerInterface
{
    public function handle(EventInterface $event): void
    {
    }
}

class PreflightCommandHandler implements HandlerInterface
{
    public function handle(DTOInterface $command): MessageInterface
    {
        throw new \LogicException('Not called during static validation.');
    }
}

class PreflightControllerFactory
{
    public static bool $called = false;

    public static function create(): RequestHandlerInterface
    {
        self::$called = true;

        return new PreflightRequestHandler();
    }
}
