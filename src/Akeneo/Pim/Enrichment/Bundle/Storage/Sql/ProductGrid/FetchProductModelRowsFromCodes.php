<?php

declare(strict_types=1);

namespace Akeneo\Pim\Enrichment\Bundle\Storage\Sql\ProductGrid;

use Akeneo\Pim\Enrichment\Component\Product\Factory\ValueCollectionFactoryInterface;
use Akeneo\Pim\Enrichment\Component\Product\Grid\Query\FetchProductAndProductModelRowsParameters;
use Akeneo\Pim\Enrichment\Component\Product\Grid\ReadModel;
use Akeneo\Pim\Enrichment\Component\Product\Model\ValueInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;

/**
 * @copyright 2018 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
final class FetchProductModelRowsFromCodes
{
    /** @var Connection */
    private $connection;

    /** @var ValueCollectionFactoryInterface */
    private $valueCollectionFactory;

    public function __construct(Connection $connection, ValueCollectionFactoryInterface $valueCollectionFactory)
    {
        $this->connection = $connection;
        $this->valueCollectionFactory = $valueCollectionFactory;
    }

    /**
     * @param array  $codes
     * @param array  $attributeCodes
     * @param string $channelCode
     * @param string $localeCode
     *
     * @return ReadModel\Row[]
     */
    public function __invoke(array $codes, array $attributeCodes, string $channelCode, string $localeCode): array
    {
        if (empty($codes)) {
            return [];
        }

        $valueCollections = $this->getValueCollection($codes, $attributeCodes, $channelCode, $localeCode);

        $rows = array_replace_recursive(
            $this->getProperties($codes),
            $this->getLabels($codes, $valueCollections, $channelCode, $localeCode),
            $this->getAttributeAsImage($codes, $valueCollections),
            $this->getChildrenCompletenesses($codes, $channelCode, $localeCode),
            $this->getFamilyLabels($codes, $localeCode),
            $valueCollections
        );

        $platform = $this->connection->getDatabasePlatform();

        $productModels = [];
        foreach ($rows as $row) {
            $productModels[] = ReadModel\Row::fromProductModel(
                $row['code'],
                $row['family_label'],
                Type::getType(Type::DATETIME)->convertToPhpValue($row['created'], $platform),
                Type::getType(Type::DATETIME)->convertToPhpValue($row['updated'], $platform),
                $row['label'],
                $row['image'],
                (int) $row['id'],
                $row['children_completeness'],
                $row['parent_code'],
                $row['value_collection']
            );
        }

        return $productModels;
    }

    private function getProperties(array $codes): array
    {
        $sql = <<<SQL
            SELECT 
                pm.id,
                pm.code,
                pm.created,
                pm.updated,
                parent.code as parent_code
            FROM
                pim_catalog_product_model pm
                LEFT JOIN pim_catalog_product_model parent ON parent.id = pm.parent_id
            WHERE 
                pm.code IN (:codes)
SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['code']] = $row;
        }

        return $result;
    }

    private function getLabels(array $codes, array $valueCollections, string $channelCode, string $localeCode): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[$code]['label'] = sprintf('[%s]', $code);
        }

        $sql = <<<SQL
            SELECT 
                pm.code,
                a_label.code as label_code,
                a_label.is_localizable,
                a_label.is_scopable
            FROM
                pim_catalog_product_model pm
                JOIN pim_catalog_family_variant fv ON fv.id = pm.family_variant_id
                JOIN pim_catalog_family f ON f.id = fv.family_id
                JOIN pim_catalog_attribute a_label ON a_label.id = f.label_attribute_id
            WHERE 
                pm.code IN (:codes)
SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        foreach ($rows as $row) {
            $label = $valueCollections[$row['code']]['value_collection']->getByCodes(
                $row['label_code'],
                $row['is_scopable'] ? $channelCode : null,
                $row['is_localizable'] ? $localeCode : null
            );

            if (null !== $label && null !== $label->getData()) {
                $result[$row['code']]['label'] = $label->getData();
            }
        }

        return $result;
    }

    private function getAttributeAsImage(array $codes, array $valueCollections): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[$code]['image'] = null;
        }

        /**
         * @TODO image from first variant product or sub-product model when not at product model level
         *        ex for product model "model-tshirt-divided"  :
         *          the code of the image attribute of the family is "variation_image"
         *          but "variation_image" is the image of the variant product. So it's the attribute "image" that is used
         */
        $sql = <<<SQL
            SELECT 
                pm.code,
                a_image.code as image_code
            FROM
                pim_catalog_product_model pm
                JOIN pim_catalog_family_variant fv ON fv.id = pm.family_variant_id
                JOIN pim_catalog_family f ON f.id = fv.family_id
                JOIN pim_catalog_attribute a_image ON a_image.id = f.image_attribute_id
            WHERE 
                pm.code IN (:codes)
SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        foreach ($rows as $row) {
            $image = $valueCollections[$row['code']]['value_collection']->getByCodes($row['image_code']);
            $result[$row['code']]['image'] = $image ?? null;
        }

        return $result;
    }

    private function getChildrenCompletenesses(array $codes, string $channelCode, string $localeCode): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[$code]['children_completeness'] = [
                'total'    => 0,
                'complete' => 0,
            ];
        }

        $sql = <<<SQL
            SELECT 
                pm.code,
                COUNT(p_child.id) AS nb_children,
                SUM(IF(completeness.ratio = 100, 1, 0)) AS nb_children_complete
            FROM 
                pim_catalog_product_model pm
                LEFT JOIN pim_catalog_product_model pm_child ON pm_child.parent_id = pm.id
                LEFT JOIN pim_catalog_product p_child ON p_child.product_model_id = COALESCE(pm_child.id, pm.id)
                LEFT JOIN pim_catalog_completeness completeness ON completeness.product_id = p_child.id
                LEFT JOIN pim_catalog_channel channel ON channel.id = completeness.channel_id
                LEFT JOIN pim_catalog_locale locale ON locale.id = completeness.locale_id
            WHERE pm.code IN (:codes)
                AND channel.code = :channel
                AND locale.code = :locale
            GROUP BY 
                pm.code
SQL;
        $rows = $this->connection->executeQuery(
            $sql,
            [
                'codes' => $codes,
                'channel' => $channelCode,
                'locale' => $localeCode,
            ],
            [
                'codes' => Connection::PARAM_STR_ARRAY,
                'channel' => \PDO::PARAM_STR,
                'locale' => \PDO::PARAM_STR,
            ]
        )->fetchAll();

        foreach ($rows as $row) {
            $result[$row['code']]['children_completeness'] = [
                'total'    => (int) $row['nb_children'],
                'complete' => (int) $row['nb_children_complete'],
            ];
        }

        return $result;
    }

    private function getFamilyLabels(array $codes, string $localeCode): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[$code]['family_label'] = null;
        }

        $sql = <<<SQL
            SELECT 
                pm.code,
                COALESCE(ft.label, CONCAT("[", f.code, "]")) as family_label
            FROM
                pim_catalog_product_model pm
                JOIN pim_catalog_family_variant fv ON fv.id = pm.family_variant_id
                JOIN pim_catalog_family f ON f.id = fv.family_id
                LEFT JOIN pim_catalog_family_translation ft ON ft.foreign_key = f.id AND ft.locale = :locale_code
            WHERE 
                pm.code IN (:codes)
SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            ['codes' => $codes, 'locale_code' => $localeCode],
            ['codes' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        foreach ($rows as $row) {
            $result[$row['code']]['family_label'] = $row['family_label'];
        }

        return $result;
    }

    private function getValueCollection(array $codes, array $attributeCodes, string $channelCode, string $localeCode): array
    {
        $sql = <<<SQL
            SELECT 
                pm.code,
                a_label.code attribute_as_label_code,
                a_image.code attribute_as_image_code,
                JSON_MERGE(COALESCE(parent.raw_values, '{}'), pm.raw_values) as raw_values
            FROM
                pim_catalog_product_model pm
                JOIN pim_catalog_family_variant fv ON fv.id = pm.family_variant_id
                JOIN pim_catalog_family f ON f.id = fv.family_id
                LEFT JOIN pim_catalog_attribute a_label ON a_label.id = f.label_attribute_id
                LEFT JOIN pim_catalog_attribute a_image ON a_image.id = f.image_attribute_id
                LEFT JOIN pim_catalog_product_model parent on parent.id = pm.parent_id
            WHERE 
                pm.code IN (:codes)
SQL;

        $rows = $this->connection->executeQuery(
            $sql,
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $values = json_decode($row['raw_values'], true);
            // filter attributes directly on raw_values for performance reason
            $attributeCodesToKeep = array_filter(
                array_merge(
                    $attributeCodes,
                    [$row['attribute_as_label_code'], $row['attribute_as_image_code']]
                )
            );

            $filteredValues = array_intersect_key($values, array_flip($attributeCodesToKeep));

            $valueCollection = $this->valueCollectionFactory->createFromStorageFormat($filteredValues);

            $result[$row['code']]['value_collection'] = $valueCollection->filter(
                function (ValueInterface $value) use ($channelCode, $localeCode) {
                    return ($value->getScopeCode() === $channelCode || $value->getScopeCode() === null)
                        && ($value->getLocaleCode() === $localeCode || $value->getLocaleCode() === null);
                }
            );
        }

        return $result;
    }
}
