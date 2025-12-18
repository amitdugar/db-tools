<?php

declare(strict_types=1);

namespace DbTools\Command;

use Symfony\Component\Console\Output\OutputInterface;

trait SpinnerTrait
{
    private static array $spinnerChars = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Create a tick callback that displays an animated spinner with elapsed time.
     *
     * @return callable The tick callback to pass to service methods
     */
    private function createSpinnerCallback(OutputInterface $output, string $message, float $startTime): callable
    {
        $charIndex = 0;

        return function () use ($message, $startTime, &$charIndex): void {
            $elapsed = microtime(true) - $startTime;
            $spinner = self::$spinnerChars[$charIndex % \count(self::$spinnerChars)];
            // Write directly to STDERR for immediate display (unbuffered)
            fwrite(STDERR, sprintf("\r  %s %s… %s  ", $spinner, $message, $this->formatDuration($elapsed)));
            $charIndex++;
        };
    }

    /**
     * Clear the spinner line from output.
     */
    private function clearSpinner(OutputInterface $output): void
    {
        // Write directly to STDERR to match spinner output
        fwrite(STDERR, "\r" . str_repeat(' ', 50) . "\r");
    }

    /**
     * Format duration in human-friendly terms.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $secs = $seconds - ($minutes * 60);

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, (int) $secs);
        }

        $hours = (int) floor($minutes / 60);
        $mins = $minutes - ($hours * 60);

        return sprintf('%dh %dm', $hours, $mins);
    }
}
