<?php
namespace Neos\EventSourcedNeosAdjustments\EventSourcedFrontController;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\NodeFactory;
use Neos\ContentRepository\Domain\Factory\NodeTypeConstraintFactory;
use Neos\ContentRepository\Domain\ValueObject\NodePath;
use Neos\EventSourcedContentRepository\Domain\Context\Node\SubtreeInterface;
use Neos\EventSourcedContentRepository\Domain\Context\Parameters\ContextParameters;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\ContentGraphInterface;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\ContentSubgraphInterface;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\InMemoryCache;
use Neos\ContentRepository\Domain\Projection\Content\NodeInterface;
use Neos\EventSourcedContentRepository\Domain\Projection\Content\TraversableNode;
use Neos\EventSourcedContentRepository\Domain\Projection\Workspace\WorkspaceFinder;
use Neos\EventSourcedNeosAdjustments\Domain\Context\Content\NodeAddress;
use Neos\EventSourcedNeosAdjustments\Domain\Context\Content\NodeAddressFactory;
use Neos\EventSourcedNeosAdjustments\Domain\Context\Content\NodeSiteResolvingService;
use Neos\EventSourcedNeosAdjustments\Domain\Service\NodeShortcutResolver;
use Neos\EventSourcedNeosAdjustments\View\FusionView;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Session\SessionInterface;
use Neos\Flow\Utility\Now;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * Event Sourced Node Controller; as Replacement of NodeController
 */
class EventSourcedNodeController extends ActionController
{
    /**
     * @Flow\Inject
     * @var ContentGraphInterface
     */
    protected $contentGraph;


    /**
     * @Flow\Inject(lazy=false)
     * @var Now
     */
    protected $now;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var WorkspaceFinder
     */
    protected $workspaceFinder;

    /**
     * @Flow\Inject
     * @var SessionInterface
     */
    protected $session;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @Flow\Inject
     * @var NodeTypeConstraintFactory
     */
    protected $nodeTypeConstraintFactory;

    /**
     * @Flow\Inject
     * @var NodeAddressFactory
     */
    protected $nodeAddressService;

    /**
     * @Flow\Inject
     * @var NodeSiteResolvingService
     */
    protected $nodeSiteResolvingService;

    /**
     * Initializes the view with the necessary parameters encoded in the given NodeAddress
     *
     * @param NodeAddress $node Legacy name for backwards compatibility of route components
     * @throws NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws \Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Session\Exception\SessionNotStartedException
     * @throws \Neos\Neos\Exception
     * @Flow\SkipCsrfProtection We need to skip CSRF protection here because this action could be called with unsafe requests from widgets or plugins that are rendered on the node - For those the CSRF token is validated on the sub-request, so it is safe to be skipped here
     */
    public function showAction(NodeAddress $node)
    {
        $nodeAddress = $node;

        $subgraph = $this->contentGraph->getSubgraphByIdentifier(
            $nodeAddress->getContentStreamIdentifier(),
            $nodeAddress->getDimensionSpacePoint()
        );

        $inBackend = !$nodeAddress->isInLiveWorkspace();

        $contextParameters = $this->createContextParameters($inBackend);
        $site = $this->nodeSiteResolvingService->findSiteNodeForNodeAddress($nodeAddress);
        if (!$site) {
            throw new NodeNotFoundException("TODO: SITE NOT FOUND; should not happen (for address " . $nodeAddress);
        }

        $this->fillCacheWithContentNodes($subgraph, $nodeAddress, $contextParameters);
        $node = $subgraph->findNodeByNodeAggregateIdentifier($nodeAddress->getNodeAggregateIdentifier());

        if (is_null($node)) {
            throw new NodeNotFoundException('The requested node does not exist or isn\'t accessible to the current user', 1430218623);
        }

        if ($node->getNodeType()->isOfType('Neos.Neos:Shortcut') && !$inBackend) {
            $this->handleShortcutNode($subgraph, $node, $nodeAddress);
        }

        $traversableNode = new TraversableNode($node, $subgraph, $contextParameters);
        $traversableSite = new TraversableNode($site, $subgraph, $contextParameters);

        $this->view->assignMultiple([
            'value' => $traversableNode,
            'subgraph' => $subgraph,
            'site' => $traversableSite,
            'contextParameters' => $contextParameters
        ]);

        if ($inBackend) {
            $this->overrideViewVariablesFromInternalArguments();
            $this->response->setHeader('Cache-Control', 'no-cache');
            if (!$this->view->canRenderWithNodeAndPath()) {
                $this->view->setFusionPath('rawContent');
            }
        }

        if ($this->session->isStarted() && $inBackend) {
            $this->session->putData('lastVisitedNode', $nodeAddress);
        }
    }


    /**
     * @param bool $inBackend
     * @return ContextParameters
     */
    protected function createContextParameters(bool $inBackend): ContextParameters
    {
        return new ContextParameters($this->now, $this->securityContext->getRoles(), $inBackend, $inBackend);
    }

    /**
     * Checks if the optionally given node context path, affected node context path and Fusion path are set
     * and overrides the rendering to use those. Will also add a "X-Neos-AffectedNodePath" header in case the
     * actually affected node is different from the one routing resolved.
     * This is used in out of band rendering for the backend.
     *
     * @return void
     * @throws NodeNotFoundException
     */
    protected function overrideViewVariablesFromInternalArguments()
    {
        if (($nodeContextPath = $this->request->getInternalArgument('__nodeContextPath')) !== null) {
            $node = $this->propertyMapper->convert($nodeContextPath, NodeInterface::class);
            if (!$node instanceof NodeInterface) {
                throw new NodeNotFoundException(sprintf('The node with context path "%s" could not be resolved', $nodeContextPath), 1437051934);
            }
            $this->view->assign('value', $node);
        }

        if (($affectedNodeContextPath = $this->request->getInternalArgument('__affectedNodeContextPath')) !== null) {
            $this->response->setHeader('X-Neos-AffectedNodePath', $affectedNodeContextPath);
        }

        if (($fusionPath = $this->request->getInternalArgument('__fusionPath')) !== null) {
            $this->view->setFusionPath($fusionPath);
        }
    }

    /**
     * Handles redirects to shortcut targets in live rendering.
     *
     * @param ContentSubgraphInterface $subgraph
     * @param NodeInterface $node
     * @param NodeAddress $nodeAddress
     * @return void
     * @throws NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws \Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function handleShortcutNode(ContentSubgraphInterface $subgraph, NodeInterface $node, NodeAddress $nodeAddress): void
    {
        $resolvedUri = $this->nodeShortcutResolver->resolveShortcutTarget($subgraph, $node, $nodeAddress, $this->uriBuilder, $this->request->getFormat());
        if (!is_null($resolvedUri)) {
            $this->redirectToUri($resolvedUri);
        } else {
            throw new NodeNotFoundException(sprintf('The shortcut node target of node "%s" could not be resolved', $node->getNodeAggregateIdentifier()), 1430218730);
        }
    }

    private function fillCacheWithContentNodes(ContentSubgraphInterface $subgraph, NodeAddress $nodeAddress, ContextParameters $contextParameters)
    {
        $subtree = $subgraph->findSubtrees([$nodeAddress->getNodeAggregateIdentifier()], 10, $contextParameters, $this->nodeTypeConstraintFactory->parseFilterString('!Neos.Neos:Document'));
        $subtree = $subtree->getChildren()[0];

        $nodeByNodeIdentifierCache = $subgraph->getInMemoryCache()->getNodeByNodeIdentifierCache();
        $nodePathCache = $subgraph->getInMemoryCache()->getNodePathCache();

        $currentDocumentNode = $subtree->getNode();
        $nodePathOfDocumentNode = $subgraph->findNodePath($currentDocumentNode->getNodeIdentifier());

        $nodeByNodeIdentifierCache->add($currentDocumentNode->getNodeIdentifier(), $currentDocumentNode);
        $nodePathCache->add($currentDocumentNode->getNodeIdentifier(), $nodePathOfDocumentNode);

        foreach ($subtree->getChildren() as $childSubtree) {
            self::fillCacheInternal($childSubtree, $currentDocumentNode, $nodePathOfDocumentNode, $subgraph->getInMemoryCache());
        }
    }

    private static function fillCacheInternal(SubtreeInterface $subtree, NodeInterface $parentNode, NodePath $parentNodePath, InMemoryCache $inMemoryCache)
    {
        $parentNodeIdentifierByChildNodeIdentifierCache = $inMemoryCache->getParentNodeIdentifierByChildNodeIdentifierCache();
        $nodeByNodeIdentifierCache = $inMemoryCache->getNodeByNodeIdentifierCache();
        $namedChildNodeByNodeIdentifierCache = $inMemoryCache->getNamedChildNodeByNodeIdentifierCache();
        $allChildNodesByNodeIdentifierCache = $inMemoryCache->getAllChildNodesByNodeIdentifierCache();
        $nodePathCache = $inMemoryCache->getNodePathCache();

        $node = $subtree->getNode();
        $nodePath = $parentNodePath->appendPathSegment($node->getNodeName());

        $parentNodeIdentifierByChildNodeIdentifierCache->add($node->getNodeIdentifier(), $parentNode->getNodeIdentifier());
        $nodeByNodeIdentifierCache->add($node->getNodeIdentifier(), $node);
        $namedChildNodeByNodeIdentifierCache->add($parentNode->getNodeIdentifier(), $node->getNodeName(), $node);
        $nodePathCache->add($node->getNodeIdentifier(), $nodePath);

        $allChildNodes = [];
        foreach ($subtree->getChildren() as $childSubtree) {
            self::fillCacheInternal($childSubtree, $node, $nodePath, $inMemoryCache);
            $allChildNodes[] = $childSubtree->getNode();
        }

        // TODO Explain why this is safe (Content can not contain other documents)
        $allChildNodesByNodeIdentifierCache->add($node->getNodeIdentifier(), $allChildNodes);
    }
}
