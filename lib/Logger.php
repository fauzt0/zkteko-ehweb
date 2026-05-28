<?php

namespace ZKTeco;

/**
 * Logger — Simple file-based logging utility.
 *
 * Writes timestamped log entries to a daily log file.
 * Supports multiple severity levels and debug toggling.
 */
class Logger
{
    /** @var string Path to the log directory */
    private string $logDir;

    /** @var bool Whether logging is enabled */
    private bool $enabled;

    /** @var bool Whether debug-level messages are written */
    private bool $debugMode;

    public const LEVEL_INFO  = 'INFO';
    public const LEVEL_WARN  = 'WARN';
    public const LEVEL_ERROR = 'ERROR';
    public const LEVEL_DEBUG = 'DEBUG';

    /**
     * @param string $logDir    Directory path for log files
     * @param bool   $enabled   Master toggle for logging
     * @param bool   $debugMode Whether to include DEBUG-level messages
     */
    public function __construct(
        string $logDir,
        bool $enabled = true,
        bool $debugMode = false
    ) {
        $this->logDir    = rtrim($logDir, '/');
        $this->enabled   = $enabled;
        $this->debugMode = $debugMode;

        if ($this->enabled && !is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Write a log entry.
     *
     * @param string $message The log message
     * @param string $level   Severity level (INFO, WARN, ERROR, DEBUG)
     */
    public function log(string $message, string $level = self::LEVEL_INFO): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($level === self::LEVEL_DEBUG && !$this->debugMode) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logFile   = $this->logDir . '/zkteco-' . date('Y-m-d') . '.log';

        $line = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Suppress write errors in production
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Shorthand for INFO-level logging.
     */
    public function info(string $message): void
    {
        $this->log($message, self::LEVEL_INFO);
    }

    /**
     * Shorthand for WARN-level logging.
     */
    public function warn(string $message): void
    {
        $this->log($message, self::LEVEL_WARN);
    }

    /**
     * Shorthand for ERROR-level logging.
     */
    public function error(string $message): void
    {
        $this->log($message, self::LEVEL_ERROR);
    }

    /**
     * Shorthand for DEBUG-level logging.
     */
    public function debug(string $message): void
    {
        $this->log($message, self::LEVEL_DEBUG);
    }
}
