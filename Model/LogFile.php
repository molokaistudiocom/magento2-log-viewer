<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Model;

/**
 * A log file that {@see LogDirectory} has checked: a regular *.log file whose real path lies inside var/log.
 * Only LogDirectory creates these, so holding one means the name is safe to read.
 */
readonly class LogFile
{
    /**
     * @param string $name path relative to var/log, e.g. "system.log" or "akeneo_connector/import.log"
     */
    public function __construct(
        public string $name,
        public int $size,
        public int $modifiedAt
    ) {
    }
}
