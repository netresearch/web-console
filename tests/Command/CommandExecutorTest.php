<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test\Command;

use Netresearch\WebConsole\Command\CommandExecutionException;
use Netresearch\WebConsole\Command\CommandExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see CommandExecutor}. Exercises the happy paths (stdout,
 * stderr capture, explicit cwd) plus the regression guards we care about
 * (cwd leaking into the PHP process, trailing newline stripping).
 */
#[CoversClass(CommandExecutor::class)]
#[UsesClass(CommandExecutionException::class)]
final class CommandExecutorTest extends TestCase
{
    private CommandExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new CommandExecutor();
    }

    #[Test]
    public function executeReturnsStdoutOfSimpleCommand(): void
    {
        self::assertSame('hello', $this->executor->execute('printf hello'));
    }

    /**
     * The trailing newline many commands emit is stripped so the RPC
     * client does not have to deal with terminal-specific line breaks.
     */
    #[Test]
    public function executeStripsTrailingNewline(): void
    {
        self::assertSame('hello', $this->executor->execute('echo hello'));
    }

    /**
     * proc_open is invoked with `$command . ' 2>&1'`, so writing to stderr
     * from the command must surface in the return value. `ls /nonexistent`
     * reliably emits "No such file or directory" on stderr and exits
     * non-zero on every POSIX shell.
     */
    #[Test]
    public function executeCapturesStderr(): void
    {
        $output = $this->executor->execute('ls /this-directory-does-not-exist 2>&1');

        self::assertStringContainsString('No such file', $output);
    }

    #[Test]
    public function executeHonoursExplicitCwd(): void
    {
        $tmp = sys_get_temp_dir();

        self::assertSame($tmp, $this->executor->execute('pwd', $tmp));
    }

    /**
     * A non-existing cwd must not make the executor crash -- it falls back
     * to the PHP process' current working directory, matching upstream
     * behaviour of proc_open(null).
     */
    #[Test]
    public function executeIgnoresNonExistingCwd(): void
    {
        $before = (string) getcwd();

        self::assertSame($before, $this->executor->execute('pwd', '/this/does/not/exist'));
    }

    #[Test]
    public function executeIgnoresEmptyCwd(): void
    {
        $before = (string) getcwd();

        self::assertSame($before, $this->executor->execute('pwd', ''));
    }

    /**
     * Regression check for upstream issues #7/#33: the executor must not
     * mutate the PHP process' cwd. Concurrent requests (in a shared
     * process) would otherwise race, because chdir() is global state.
     */
    #[Test]
    public function executeDoesNotLeakProcessCwdBetweenCalls(): void
    {
        $before = (string) getcwd();

        $this->executor->execute('pwd', sys_get_temp_dir());

        self::assertSame($before, (string) getcwd());
    }
}
