<?php

declare(strict_types=1);

namespace Miniorange\Otp\Domain\Repository\Site;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the Main Website for the Free 2FA extension.
 */
class SiteRepository
{
    /**
     * Return the Main Website.
     *
     * TYPO3 has no dedicated "main site" flag. The site with the lowest
     * root page ID is used as a stable, deterministic Main Website.
     */
    public function findMain(): ?Site
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = array_values($siteFinder->getAllSites());
        if ($sites === []) {
            return null;
        }

        usort(
            $sites,
            static function (Site $firstSite, Site $secondSite): int {
                return $firstSite->getRootPageId() <=> $secondSite->getRootPageId();
            }
        );

        return $sites[0];
    }

    /**
     * Return the Main Website identifier, or null when no site exists.
     */
    public function findMainIdentifier(): ?string
    {
        $mainSite = $this->findMain();

        return $mainSite !== null ? $mainSite->getIdentifier() : null;
    }
}
