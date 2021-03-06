<?php

declare(strict_types=1);

/*
 * This file is part of the Akeneo PIM Enterprise Edition.
 *
 * (c) 2020 Akeneo SAS (http://www.akeneo.com)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Akeneo\Tool\Bundle\MeasureBundle\tests\Integration\Persistence;

use Akeneo\Tool\Bundle\MeasureBundle\Exception\MeasurementFamilyNotFoundException;
use Akeneo\Tool\Bundle\MeasureBundle\Model\LabelCollection;
use Akeneo\Tool\Bundle\MeasureBundle\Model\MeasurementFamily;
use Akeneo\Tool\Bundle\MeasureBundle\Model\MeasurementFamilyCode;
use Akeneo\Tool\Bundle\MeasureBundle\Model\Operation;
use Akeneo\Tool\Bundle\MeasureBundle\Model\Unit;
use Akeneo\Tool\Bundle\MeasureBundle\Model\UnitCode;
use Akeneo\Tool\Bundle\MeasureBundle\Persistence\MeasurementFamilyRepositoryInterface;
use Akeneo\Tool\Bundle\MeasureBundle\tests\Integration\SqlIntegrationTestCase;

class MeasurementFamilyRepositoryIntegration extends SqlIntegrationTestCase
{
    /** @var MeasurementFamilyRepositoryInterface */
    private $repository;

    public function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->get('akeneo_measure.persistence.measurement_family_repository');
        $this->loadSomeMetrics();
    }

    /**
     * @test
     */
    public function it_returns_all_measurement_families()
    {
        $measurementFamilies = $this->repository->all();

        $this->assertCount(2, $measurementFamilies);
        $this->assertEquals($this->createMeasurementFamily(), $measurementFamilies[0]);
    }

    /**
     * @test
     */
    public function it_returns_a_measurement_family_using_the_provided_code(): void
    {
        $measurementFamily = $this->repository->getByCode(MeasurementFamilyCode::fromString('Area'));

        $this->assertEquals($this->createMeasurementFamily(), $measurementFamily);
    }

    /**
     * @test
     */
    public function it_throws_when_the_measurement_family_does_not_exists(): void
    {
        $this->expectException(MeasurementFamilyNotFoundException::class);

        $this->repository->getByCode(MeasurementFamilyCode::fromString('NOT_EXISTING'));
    }

    /**
     * @test
     */
    public function it_updates_an_existing_measurement_family_if_it_exists(): void
    {
        $area = $this->createMeasurementFamily('Area', ["en_US" => "New area label", "fr_FR" => "Nouveau surface label"]);
        $this->repository->save($area);

        $updatedArea = $this->repository->getByCode(MeasurementFamilyCode::fromString('Area'));
        $this->assertEquals($area, $updatedArea);
    }

    /**
     * @test
     */
    public function it_creates_an_new_measurement_family_if_the_code_is_not_present(): void
    {
        $measurementFamilies = $this->repository->all();
        $this->assertCount(2, $measurementFamilies);

        $newFamily = $this->createMeasurementFamily('NewFamily', ["en_US" => "New family label", "fr_FR" => "Nouveau famille label"]);
        $this->repository->save($newFamily);

        $newFamilyFetched = $this->repository->getByCode(MeasurementFamilyCode::fromString('NewFamily'));
        $this->assertEquals($newFamily, $newFamilyFetched);

        $measurementFamilies = $this->repository->all();
        $this->assertCount(3, $measurementFamilies);
    }

    private function createMeasurementFamily(string $code = 'Area', array $labels = ["en_US" => "Area", "fr_FR" => "Surface"]): MeasurementFamily
    {
        return MeasurementFamily::create(
            MeasurementFamilyCode::fromString($code),
            LabelCollection::fromArray($labels),
            UnitCode::fromString('SQUARE_MILLIMETER'),
            [
                Unit::create(
                    UnitCode::fromString('SQUARE_MILLIMETER'),
                    LabelCollection::fromArray(["en_US" => "Square millimeter", "fr_FR" => "Millimètre carré"]),
                    [Operation::create("mul", "0.000001")],
                    "mm²",
                ),
                Unit::create(
                    UnitCode::fromString('SQUARE_CENTIMETER'),
                    LabelCollection::fromArray(["en_US" => "Square centimeter", "fr_FR" => "Centimètre carré"]),
                    [Operation::create("mul", "0.0001")],
                    "cm²",
                )
            ]
        );
    }

    private function loadSomeMetrics(): void
    {
        $sql = <<<SQL
TRUNCATE TABLE `akeneo_measurement`;
INSERT INTO `akeneo_measurement` (`code`, `labels`, `standard_unit`, `units`)
VALUES
	('Area', '{\"en_US\": \"Area\", \"fr_FR\": \"Surface\"}', 'SQUARE_MILLIMETER', '[{\"code\": \"SQUARE_MILLIMETER\", \"labels\": {\"en_US\": \"Square millimeter\", \"fr_FR\": \"Millimètre carré\"}, \"symbol\": \"mm²\", \"convert_from_standard\": [{\"value\": \"0.000001\", \"operator\": \"mul\"}]}, {\"code\": \"SQUARE_CENTIMETER\", \"labels\": {\"en_US\": \"Square centimeter\", \"fr_FR\": \"Centimètre carré\"}, \"symbol\": \"cm²\", \"convert_from_standard\": [{\"value\": \"0.0001\", \"operator\": \"mul\"}]}]'),
	('Binary', '{\"en_US\": \"Binary\", \"fr_FR\": \"Binaire\"}', 'BYTE', '[{\"code\": \"BIT\", \"labels\": {\"en_US\": \"Bit\", \"fr_FR\": \"Bit\"}, \"symbol\": \"b\", \"convert_from_standard\": [{\"value\": \"0.125\", \"operator\": \"mul\"}]}, {\"code\": \"BYTE\", \"labels\": {\"en_US\": \"Byte\", \"fr_FR\": \"Octet\"}, \"symbol\": \"B\", \"convert_from_standard\": [{\"value\": \"1\", \"operator\": \"mul\"}]}]');
SQL;
        $this->connection->executeQuery($sql);
    }
}
