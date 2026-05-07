<?php

/**
 * InstallationCheck - Verifies OpenEMR installation status
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Health\Check;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Health\HealthCheckInterface;
use OpenEMR\Health\HealthCheckResult;

class InstallationCheck implements HealthCheckInterface
{
    public const NAME = 'installed';

    public function getName(): string
    {
        return static::NAME;
    }

    public function check(): HealthCheckResult
    {
        try {
            $siteDir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR');
            if ($siteDir === '') {
                return new HealthCheckResult(
                    $this->getName(),
                    false,
                    'Configuration not loaded'
                );
            }

            $sqlconfPath = $siteDir . '/sqlconf.php';
            if (!file_exists($sqlconfPath)) {
                return new HealthCheckResult(
                    $this->getName(),
                    false,
                    'Configuration not loaded'
                );
            }

            // Parse $config from the file text instead of using global $config,
            // which sql.inc.php overwrites with a DatabaseConnectionOptions object.
            $contents = file_get_contents($sqlconfPath);
            if (!is_string($contents)) {
                return new HealthCheckResult(
                    $this->getName(),
                    false,
                    'Configuration not readable'
                );
            }

            if (preg_match('/\$config\s*=\s*(\d+)\s*;/', $contents, $matches) !== 1) {
                return new HealthCheckResult(
                    $this->getName(),
                    false,
                    'OpenEMR setup required'
                );
            }

            if ((int) $matches[1] !== 1) {
                return new HealthCheckResult(
                    $this->getName(),
                    false,
                    'OpenEMR setup required'
                );
            }

            return new HealthCheckResult($this->getName(), true);
        } catch (\Throwable $e) {
            return new HealthCheckResult(
                $this->getName(),
                false,
                $e->getMessage()
            );
        }
    }
}
