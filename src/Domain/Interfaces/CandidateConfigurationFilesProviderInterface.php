<?php

declare(strict_types=1);

namespace Flexi\Domain\Interfaces;

/** Adds a non-persisted module to the configuration view for activation preflight. */
interface CandidateConfigurationFilesProviderInterface extends ConfigurationFilesProviderInterface
{
    /** @return array<string, string[]> */
    public function getAllConfigurationFilesIncluding(string $moduleName, bool $includeCoreConfig = true): array;
}
