<?php
namespace Neos\Node\Routing\Http;

use TYPO3\Flow\Annotations as Flow;
use Neos\Node\Routing\Node\RequestedNodeContextHolder;
use TYPO3\Flow\Http\Component\ComponentContext;
use TYPO3\Flow\Http\Component\ComponentInterface;
use TYPO3\Neos\Domain\Repository\DomainRepository;
use TYPO3\Neos\Domain\Repository\SiteRepository;

/**
 *
 */
class DomainMatchingComponent implements ComponentInterface
{
    /**
     * @Flow\Inject
     * @var RequestedNodeContextHolder
     */
    protected $requestedNodeContextHolder;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @param ComponentContext $componentContext
     */
    public function handle(ComponentContext $componentContext)
    {
        $httpRequest = $componentContext->getHttpRequest();
        $matchingDomain = $this->domainRepository->findOneByHost($httpRequest->getUri()->getHost(), true);
        if ($matchingDomain !== null) {
            $this->requestedNodeContextHolder->setPropertyByPath('currentSite', $matchingDomain->getSite());
            $this->requestedNodeContextHolder->setPropertyByPath('currentDomain', $matchingDomain);
        } else {
            $this->requestedNodeContextHolder->setPropertyByPath('currentSite', $this->siteRepository->findFirstOnline());
        }
    }
}
