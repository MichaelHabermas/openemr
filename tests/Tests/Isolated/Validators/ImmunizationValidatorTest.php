<?php

/**
 * Isolated ImmunizationValidator Test
 *
 * Tests ImmunizationValidator validation logic without database dependencies.
 * Note: ImmunizationValidator currently only inherits from BaseValidator
 * without adding specific validation rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Validators;

use OpenEMR\Validators\BaseValidator;
use OpenEMR\Validators\ImmunizationValidator;
use PHPUnit\Framework\TestCase;

class ImmunizationValidatorTest extends TestCase
{
    private ImmunizationValidatorStub $validator;

    protected function setUp(): void
    {
        $this->validator = new ImmunizationValidatorStub();
    }

    public function testValidatorInheritsFromBaseValidator(): void
    {
        $this->assertInstanceOf(BaseValidator::class, $this->validator);
    }

    public function testValidatorIsInstantiable(): void
    {
        $validator = new ImmunizationValidatorStub();
        $this->assertInstanceOf(ImmunizationValidator::class, $validator);
    }

    public function testValidatorSupportsInsertContext(): void
    {
        // ImmunizationValidator currently inherits BaseValidator contexts but does not add immunization-specific rules.
        // Executing validation against an empty Particle context produces vendor warnings, so this test documents the
        // supported-context contract without treating the unimplemented rule set as valid behavior.
        $this->assertContains(BaseValidator::DATABASE_INSERT_CONTEXT, $this->getSupportedContexts());
    }

    public function testValidatorSupportsUpdateContext(): void
    {
        $this->assertContains(BaseValidator::DATABASE_UPDATE_CONTEXT, $this->getSupportedContexts());
    }

    public function testValidatorClassExists(): void
    {
        // Basic test to ensure the class can be instantiated and exists
        $this->assertTrue(class_exists(ImmunizationValidator::class));
    }

    public function testValidatorHasConfigureValidatorMethod(): void
    {
        // Test that the configureValidator method exists (even though it's empty)
        $this->assertTrue(method_exists($this->validator, 'configureValidator'));
    }

    /** @return list<string> */
    private function getSupportedContexts(): array
    {
        $contexts = (fn (): array => $this->supportedContexts)->call($this->validator);
        $supportedContexts = [];
        foreach ($contexts as $context) {
            if (is_string($context)) {
                $supportedContexts[] = $context;
            }
        }

        return $supportedContexts;
    }
}

/**
 * Test stub that overrides database-dependent methods
 */
class ImmunizationValidatorStub extends ImmunizationValidator
{
    /**
     * Override validateId to avoid database calls
     */
    public static function validateId($field, $table, $lookupId, $isUuid = false)
    {
        // For testing purposes, assume all IDs are valid
        return true;
    }
}
