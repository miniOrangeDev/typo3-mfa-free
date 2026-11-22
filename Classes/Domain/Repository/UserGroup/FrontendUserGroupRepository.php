<?php

namespace Miniorange\Otp\Domain\Repository\UserGroup;

class FrontendUserGroupRepository extends AbstractUserGroupRepository
{
    const TABLE_NAME = 'fe_groups';

    protected function setTableName(): void
    {
        $this->tableName = self::TABLE_NAME;
    }
}