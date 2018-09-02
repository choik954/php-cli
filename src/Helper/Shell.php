<?php

/*
 * This file is part of the PHP-CLI package.
 *
 * (c) Jitendra Adhikari <jiten.adhikary@gmail.com>
 *     <https://github.com/adhocore>
 *
 * Licensed under MIT license.
 */

namespace Ahc\Cli\Helper;

use Ahc\Cli\Exception\RuntimeException;

/*
 * A thin proc_open wrapper to execute shell commands.
 * @author Sushil Gupta <desushil@gmail.com>
 * @license MIT
 */
class Shell
{
    const STDIN_DESCRIPTOR_KEY  = 0;
    const STDOUT_DESCRIPTOR_KEY = 1;
    const STDERR_DESCRIPTOR_KEY = 2;

    const STATE_READY      = 'ready';
    const STATE_STARTED    = 'started';
    const STATE_TERMINATED = 'terminated';

    /** @var string Command to be executed */
    protected $command;

    /** @var array Descriptor to be passed for proc_open */
    protected $descriptors;

    /** @var int Exit code of the process once it has been terminated */
    protected $exitCode = null;

    /** @var string Input for stdin */
    protected $input;

    /** @var array Pointers to stdin, stdout & stderr */
    protected $pipes = null;

    /** @var resource The actual process resource returned from proc_open */
    protected $process = null;

    /** @var string Current state of the shell execution */
    protected $state = self::STATE_READY;

    /** @var string Status of the process as returned from proc_get_status */
    protected $processStatus = null;

    public function __construct(string $command, string $input = null)
    {
        if (!\function_exists('proc_open')) {
            throw new RuntimeException('Required proc_open could not be found in your PHP setup');
        }

        $this->command = $command;
        $this->input   = $input;
    }

    protected function getDescriptors()
    {
        $out = '\\' === \DIRECTORY_SEPARATOR ? ['file', 'NUL', 'w'] : ['pipe', 'w'];

        return [
            self::STDIN_DESCRIPTOR_KEY  => ['pipe', 'r'],
            self::STDOUT_DESCRIPTOR_KEY => $out,
            self::STDERR_DESCRIPTOR_KEY => $out,
        ];
    }

    protected function setInput()
    {
        \fwrite($this->pipes[self::STDIN_DESCRIPTOR_KEY], $this->input);
    }

    protected function updateProcessStatus()
    {
        if ($this->state !== self::STATE_STARTED) {
            return;
        }

        $this->processStatus = \proc_get_status($this->process);

        if ($this->processStatus['running'] === false && $this->exitCode === null) {
            $this->exitCode = $this->processStatus['exitcode'];
        }
    }

    protected function closePipes()
    {
        \fclose($this->pipes[self::STDIN_DESCRIPTOR_KEY]);
        \fclose($this->pipes[self::STDOUT_DESCRIPTOR_KEY]);
        \fclose($this->pipes[self::STDERR_DESCRIPTOR_KEY]);
    }

    public function execute()
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Process is already running');
        }

        $this->descriptors = $this->getDescriptors();

        $this->process = proc_open($this->command, $this->descriptors, $this->pipes);

        if (!\is_resource($this->process)) {
            throw new RuntimeException('Bad program could not be started.');
        }

        $this->state = self::STATE_STARTED;

        $this->setInput();
        $this->updateProcessStatus();
    }

    public function getState()
    {
        return $this->state;
    }

    public function getOutput()
    {
        return \stream_get_contents($this->pipes[self::STDOUT_DESCRIPTOR_KEY]);
    }

    public function getErrorOutput()
    {
        return \stream_get_contents($this->pipes[self::STDERR_DESCRIPTOR_KEY]);
    }

    public function getExitCode()
    {
        $this->updateProcessStatus();

        return $this->exitCode;
    }

    public function isRunning()
    {
        if (self::STATE_STARTED !== $this->state) {
            return false;
        }

        $this->updateProcessStatus();

        return $this->processStatus['running'];
    }

    public function getProcessId()
    {
        return $this->isRunning() ? $this->processStatus['pid'] : null;
    }

    public function stop()
    {
        $this->closePipes();

        if (\is_resource($this->process)) {
            \proc_close($this->process);
        }

        $this->state = self::STATE_TERMINATED;

        $this->exitCode = $this->processStatus['exitcode'];

        return $this->exitCode;
    }

    public function kill()
    {
        return \proc_terminate($this->process);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
