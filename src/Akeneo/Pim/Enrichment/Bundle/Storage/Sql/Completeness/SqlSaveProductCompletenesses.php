<?php

declare(strict_types=1);

namespace Akeneo\Pim\Enrichment\Bundle\Storage\Sql\Completeness;

use Akeneo\Pim\Enrichment\Component\Product\Completeness\Model\ProductCompletenessWithMissingAttributeCodesCollection;
use Akeneo\Pim\Enrichment\Component\Product\Query\SaveProductCompletenesses;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * @author    Mathias METAYER <mathias.metayer@akeneo.com>
 * @copyright 2019 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
final class SqlSaveProductCompletenesses implements SaveProductCompletenesses
{
    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function save(ProductCompletenessWithMissingAttributeCodesCollection $completenesses): void
    {
        $this->saveAll([$completenesses]);
    }

    /**
     * We use INSERT statements with multiple VALUES lists to insert several rows at a time. Performance is 7 times better
     * on the icecat catalog this way, instead of using separate single-row INSERT statements.
     *
     * @see https://dev.mysql.com/doc/refman/5.7/en/insert-optimization.html
     *
     * There is retry strategy to mitigate the risk of dead lock when loading data with high concurrency.
     * With a high concurrency (30 threads), despite the retry strategy, it's still possible to have dead locks.
     * In that case, we serialize the queries by locking the completeness table.
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/innodb-deadlocks-handling.html
     *
     * {@inheritdoc}
     */
    public function saveAll(array $productCompletenessCollections): void
    {
        // it gets the data outside of the transaction to avoid to lock the tables "pim_catalog_locale" and "pim_catalog_channel"
        // when it locks the completeness table as a last attempt after failing 5 times due to deadlocks
        $localeIdsFromCode = $this->localeIdsIndexedByLocaleCodes();
        $channelIdsFromCode = $this->channelIdsIndexedByChannelCodes();

        $deleteAndInsertFunction = function () use ($productCompletenessCollections, $localeIdsFromCode, $channelIdsFromCode) {
            $productIds = array_unique(array_map(function (ProductCompletenessWithMissingAttributeCodesCollection $productCompletenessCollection) {
                return $productCompletenessCollection->productId();
            }, $productCompletenessCollections));

            $this->connection->executeQuery(
                'DELETE FROM pim_catalog_completeness WHERE product_id IN (:product_ids)',
                ['product_ids' => $productIds],
                ['product_ids' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
            );

            $numberCompletenessRow = 0;
            foreach ($productCompletenessCollections as $productCompletenessCollection) {
                $numberCompletenessRow += count($productCompletenessCollection);
            }
            $placeholders = implode(',', array_fill(0, $numberCompletenessRow, '(?, ?, ?, ?, ?)'));

            if (empty($placeholders)) {
                return;
            }

            $insert = <<<SQL
                        INSERT INTO pim_catalog_completeness
                            (locale_id, channel_id, product_id, missing_count, required_count)
                        VALUES
                            $placeholders
SQL;

            $stmt = $this->connection->prepare($insert);

            $placeholderIndex = 1;
            foreach ($productCompletenessCollections as $productCompletenessCollection) {
                foreach ($productCompletenessCollection as $productCompleteness) {
                    $stmt->bindValue($placeholderIndex++, $localeIdsFromCode[$productCompleteness->localeCode()]);
                    $stmt->bindValue($placeholderIndex++, $channelIdsFromCode[$productCompleteness->channelCode()]);
                    $stmt->bindValue($placeholderIndex++, $productCompletenessCollection->productId(), ParameterType::INTEGER);
                    $stmt->bindValue($placeholderIndex++, count($productCompleteness->missingAttributeCodes()), ParameterType::INTEGER);
                    $stmt->bindValue($placeholderIndex++, $productCompleteness->requiredCount(), ParameterType::INTEGER);
                }
            }

            $stmt->execute();
        };

        try {
            $this->executeWithRetry($deleteAndInsertFunction);
        } catch (DeadlockException $e) {
            $this->executeWithLockOnTable($deleteAndInsertFunction);
        }
    }

    private function localeIdsIndexedByLocaleCodes(): array
    {
        $query = 'SELECT locale.id as locale_id, locale.code as locale_code FROM pim_catalog_locale locale';

        $rows = $this->connection->fetchAll($query);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['locale_code']] = $row['locale_id'];
        }

        return $result;
    }

    private function channelIdsIndexedByChannelCodes(): array
    {
        $query = 'SELECT channel.id as channel_id, channel.code as channel_code FROM pim_catalog_channel channel';
        $rows = $this->connection->fetchAll($query);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['channel_code']] = $row['channel_id'];
        }

        return $result;
    }

    /**
     * To avoid to get several dead lock exceptions in a row, we sleep between the retry. It lets the database take a breath and finish the other concurrent transactions triggering the deadlock.
     * There is a random sleep as well, to avoid to restart at the same time the other concurrent processes doing a retry as well.
     */
    private function executeWithRetry(callable $function): void
    {
        $retry = 0;
        $isError = true;
        while (true === $isError) {
            try {
                $this->connection->transactional($function);

                $isError = false;
            } catch (DeadlockException $e) {
                $retry += 1;

                if (5 === $retry) {
                    throw $e;
                }

                $this->logger->warning(sprintf('Deadlock occurred when persisting the completeness, %s/4 retry', $retry));
                usleep(300000 + rand(50000, $retry * 100000));
            }
        }
    }

    /**
     * We don't catch any exception if an error occurs, because it's the last
     * attempt to insert the data by locking the completeness table.  Do note
     * that it locks also the table in READ mode for all the foreign keys
     * (locale, channel, product). It means that you can't insert data in the
     * product table also (just read). This is why we disable the foreign key
     * checks during this transaction to ovoid locking the product table.
     */
    private function executeWithLockOnTable(callable $function): void
    {
        $this->logger->warning('Locking the whole completeness table to persist the completeness, as it fails after trying 5 times to insert data due to deadlocks.');

        $value = $this->connection->executeQuery('SELECT @@autocommit, @@foreign_key_checks')->fetch();
        if (!isset($value['@@autocommit']) && ((int) $value['@@autocommit'] !== 1 || (int) $value['@@autocommit'] !== 0)) {
            throw new \RuntimeException('Error when getting autocommit parameter from Mysql.');
        }
        if (!isset($value['@@foreign_key_checks']) && ((int) $value['@@foreign_key_checks'] !== 1 || (int) $value['@@foreign_key_checks'] !== 0)) {
            throw new \RuntimeException('Error when getting foreign_key_checks parameter from Mysql.');
        }

        $formerAutocommitValue = (int) $value['@@autocommit'];
        $formerForeignKeyChecksValue = (int) $value['@@foreign_key_checks'];
        try {
            $this->connection->executeQuery('SET autocommit=0');
            $this->connection->executeQuery('SET foreign_key_checks=0');
            $this->connection->executeQuery('LOCK TABLES pim_catalog_completeness WRITE');
            $function();
            $this->connection->executeQuery('COMMIT');
            $this->connection->executeQuery('UNLOCK TABLES');
        } finally {
            $this->connection->executeQuery(sprintf('SET autocommit=%d', $formerAutocommitValue));
            $this->connection->executeQuery(sprintf('SET foreign_key_checks=%d', $formerForeignKeyChecksValue));
        }
    }
}
