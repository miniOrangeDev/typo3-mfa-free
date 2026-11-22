<?php

declare(strict_types=1);

namespace Miniorange\Otp\Domain\Repository\UserGroup;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractUserGroupRepository
{
    protected string $tableName;

    public function __construct()
    {
        $this->setTableName();
    }

    abstract protected function setTableName(): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->getQueryBuilder();

        $result = $queryBuilder->select('*')
            ->from($this->tableName)
            ->executeQuery();

        return $result->fetchAllAssociative();
    }

    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableName);
    }
}
