<?php
namespace Neos\Neos\Ui\Controller;

/*
 * This file is part of the Neos.Neos.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\RequestInterface;
use Neos\Flow\Mvc\ResponseInterface;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Service\PublishingService;
use Neos\Neos\Service\UserService;
use Neos\Neos\Ui\ContentRepository\Service\NodeService;
use Neos\Neos\Ui\ContentRepository\Service\WorkspaceService;
use Neos\Neos\Ui\Domain\Model\ChangeCollection;
use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Error;
use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Info;
use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Success;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\Redirect;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\ReloadDocument;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\RemoveNode;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateWorkspaceInfo;
use Neos\Neos\Ui\Domain\Model\FeedbackCollection;
use Neos\Neos\Ui\Service\NodeClipboard;
use Neos\Neos\Ui\Service\NodePolicyService;
use Neos\Neos\Ui\Domain\Service\NodeTreeBuilder;
use Neos\Neos\Ui\Fusion\Helper\NodeInfoHelper;
use Neos\Neos\Ui\Fusion\Helper\WorkspaceHelper;

class BackendServiceController extends ActionController
{
    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * @var string
     */
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\Inject
     * @var FeedbackCollection
     */
    protected $feedbackCollection;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceService
     */
    protected $workspaceService;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var NodePolicyService
     */
    protected $nodePolicyService;

    /**
     * @Flow\Inject
     * @var NodeClipboard
     */
    protected $clipboard;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionsPresetSource;

    /**
     * Set the controller context on the feedback collection after the controller
     * has been initialized
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function initializeController(RequestInterface $request, ResponseInterface $response)
    {
        parent::initializeController($request, $response);
        $this->feedbackCollection->setControllerContext($this->getControllerContext());
    }

    /**
     * Helper method to inform the client, that new workspace information is available
     *
     * @param string $documentNodeContextPath
     * @return void
     */
    protected function updateWorkspaceInfo(string $documentNodeContextPath)
    {
        $updateWorkspaceInfo = new UpdateWorkspaceInfo();
        $documentNode = $this->nodeService->getNodeFromContextPath($documentNodeContextPath, null, null, true);
        $updateWorkspaceInfo->setWorkspace(
            $documentNode->getContext()->getWorkspace()
        );

        $this->feedbackCollection->add($updateWorkspaceInfo);
    }

    /**
     * Apply a set of changes to the system
     *
     * @param ChangeCollection $changes
     * @return void
     */
    public function changeAction(ChangeCollection $changes)
    {
        try {
            $count = $changes->count();
            $changes->apply();

            $success = new Info();
            $success->setMessage(sprintf('%d change(s) successfully applied.', $count));

            $this->feedbackCollection->add($success);
            $this->persistenceManager->persistAll();
        } catch (\Exception $e) {
            $error = new Error();
            $error->setMessage($e->getMessage());

            $this->feedbackCollection->add($error);
        }

        $this->view->assign('value', $this->feedbackCollection);
    }

    /**
     * Publish nodes
     *
     * @param array $nodeContextPaths
     * @param string $targetWorkspaceName
     * @return void
     */
    public function publishAction(array $nodeContextPaths, string $targetWorkspaceName)
    {
        try {
            $targetWorkspace = $this->workspaceRepository->findOneByName($targetWorkspaceName);

            foreach ($nodeContextPaths as $contextPath) {
                $node = $this->nodeService->getNodeFromContextPath($contextPath, null, null, true);
                $this->publishingService->publishNode($node, $targetWorkspace);
            }

            $success = new Success();
            $success->setMessage(sprintf('Published %d change(s) to %s.', count($nodeContextPaths), $targetWorkspaceName));

            $this->updateWorkspaceInfo($nodeContextPaths[0]);
            $this->feedbackCollection->add($success);

            $this->persistenceManager->persistAll();
        } catch (\Exception $e) {
            $error = new Error();
            $error->setMessage($e->getMessage());

            $this->feedbackCollection->add($error);
        }

        $this->view->assign('value', $this->feedbackCollection);
    }

    /**
     * Discard nodes
     *
     * @param array $nodeContextPaths
     * @return void
     */
    public function discardAction(array $nodeContextPaths)
    {
        try {
            foreach ($nodeContextPaths as $contextPath) {
                $node = $this->nodeService->getNodeFromContextPath($contextPath, null, null, true);
                if ($node->isRemoved() === true) {
                    // When discarding node removal we should re-create it
                    $updateNodeInfo = new UpdateNodeInfo();
                    $updateNodeInfo->setNode($node);
                    $updateNodeInfo->recursive();
                    $this->feedbackCollection->add($updateNodeInfo);

                    // handle parent node, if needed
                    $parentNode = $node->getParent();
                    if ($parentNode instanceof NodeInterface) {
                        $updateParentNodeInfo = new UpdateNodeInfo();
                        $updateParentNodeInfo->setNode($parentNode);
                        $this->feedbackCollection->add($updateParentNodeInfo);
                    }

                    // Reload document for content node changes
                    // (as we can't RenderContentOutOfBand from here, we don't know dom addresses)
                    if (!$this->nodeService->isDocument($node)) {
                        $reloadDocument = new ReloadDocument();
                        $this->feedbackCollection->add($reloadDocument);
                    }
                } elseif (!$this->nodeService->nodeExistsInWorkspace($node, $node->getWorkSpace()->getBaseWorkspace())) {
                    // If the node doesn't exist in the target workspace, tell the UI to remove it
                    $removeNode = new RemoveNode();
                    $removeNode->setNode($node);
                    $this->feedbackCollection->add($removeNode);
                }

                $this->publishingService->discardNode($node);
            }

            $success = new Success();
            $success->setMessage(sprintf('Discarded %d node(s).', count($nodeContextPaths)));

            $this->updateWorkspaceInfo($nodeContextPaths[0]);
            $this->feedbackCollection->add($success);

            $this->persistenceManager->persistAll();
        } catch (\Exception $e) {
            $error = new Error();
            $error->setMessage($e->getMessage());

            $this->feedbackCollection->add($error);
        }

        $this->view->assign('value', $this->feedbackCollection);
    }

    /**
     * Change base workspace of current user workspace
     *
     * @param string $targetWorkspaceName ,
     * @param NodeInterface $documentNode
     * @return void
     * @throws \Exception
     */
    public function changeBaseWorkspaceAction(string $targetWorkspaceName, NodeInterface $documentNode)
    {
        try {
            $targetWorkspace = $this->workspaceRepository->findOneByName($targetWorkspaceName);
            $userWorkspace = $this->userService->getPersonalWorkspace();

            if (count($this->workspaceService->getPublishableNodeInfo($userWorkspace)) > 0) {
                // TODO: proper error dialog
                throw new \Exception('Your personal workspace currently contains unpublished changes. In order to switch to a different target workspace you need to either publish or discard pending changes first.');
            }

            $userWorkspace->setBaseWorkspace($targetWorkspace);
            $this->workspaceRepository->update($userWorkspace);

            $success = new Success();
            $success->setMessage(sprintf('Switched base workspace to %s.', $targetWorkspaceName));
            $this->feedbackCollection->add($success);

            $updateWorkspaceInfo = new UpdateWorkspaceInfo();
            $updateWorkspaceInfo->setWorkspace($userWorkspace);
            $this->feedbackCollection->add($updateWorkspaceInfo);

            // Construct base workspace context
            $originalContext = $documentNode->getContext();
            $contextProperties = $documentNode->getContext()->getProperties();
            $contextProperties['workspaceName'] = $targetWorkspaceName;
            $contentContext = $this->contextFactory->create($contextProperties);

            // If current document node doesn't exist in the base workspace, traverse its parents to find the one that exists
            $redirectNode = $documentNode;
            while (true) {
                $redirectNodeInBaseWorkspace = $contentContext->getNodeByIdentifier($redirectNode->getIdentifier());
                if ($redirectNodeInBaseWorkspace) {
                    break;
                } else {
                    $redirectNode = $redirectNode->getParent();
                    if (!$redirectNode) {
                        throw new \Exception(sprintf('Wasn\'t able to locate any valid node in rootline of node %s in the workspace %s.', $documentNode->getContextPath(), $targetWorkspaceName), 1458814469);
                    }
                }
            }

            // If current document node exists in the base workspace, then reload, else redirect
            if ($redirectNode === $documentNode) {
                $reloadDocument = new ReloadDocument();
                $reloadDocument->setNode($documentNode);
                $this->feedbackCollection->add($reloadDocument);
            } else {
                $redirect = new Redirect();
                $redirect->setNode($redirectNode);
                $this->feedbackCollection->add($redirect);
            }

            $this->persistenceManager->persistAll();
        } catch (\Exception $e) {
            $error = new Error();
            $error->setMessage($e->getMessage());

            $this->feedbackCollection->add($error);
        }

        $this->view->assign('value', $this->feedbackCollection);
    }

    /**
     * Persists the clipboard node on copy
     *
     * @param NodeInterface $node
     * @return void
     */
    public function copyNodeAction(NodeInterface $node)
    {
        $this->clipboard->copyNode($node);
    }

    /**
     * Clears the clipboard state
     *
     * @return void
     */
    public function clearClipboardAction()
    {
        $this->clipboard->clear();
    }

    /**
     * Persists the clipboard node on cut
     *
     * @param NodeInterface $node
     * @return void
     */
    public function cutNodeAction(NodeInterface $node)
    {
        $this->clipboard->cutNode($node);
    }

    public function getWorkspaceInfoAction()
    {
        $workspaceHelper = new WorkspaceHelper();
        $personalWorkspaceInfo = $workspaceHelper->getPersonalWorkspace();
        $this->view->assign('value', $personalWorkspaceInfo);
    }

    public function initializeLoadTreeAction()
    {
        $this->arguments['nodeTreeArguments']->getPropertyMappingConfiguration()->allowAllProperties();
    }

    /**
     * Load the nodetree
     *
     * @param NodeTreeBuilder $nodeTreeArguments
     * @param boolean $includeRoot
     * @return void
     */
    public function loadTreeAction(NodeTreeBuilder $nodeTreeArguments, $includeRoot = false)
    {
        $nodeTreeArguments->setControllerContext($this->controllerContext);
        $this->view->assign('value', $nodeTreeArguments->build($includeRoot));
    }

    /**
     * @throws \Neos\Flow\Mvc\Exception\NoSuchArgumentException
     */
    public function initializeGetAdditionalNodeMetadataAction()
    {
        $this->arguments->getArgument('nodes')->getPropertyMappingConfiguration()->allowAllProperties();
    }

    /**
     * Fetches all the node information that can be lazy-loaded
     *
     * @param array<NodeInterface> $nodes
     */
    public function getAdditionalNodeMetadataAction(array $nodes)
    {
        $result = [];
        /** @var NodeInterface $node */
        foreach ($nodes as $node) {
            $otherNodeVariants = array_values(array_filter(array_map(function ($node) {
                return $this->getCurrentDimensionPresetIdentifiersForNode($node);
            }, $node->getOtherNodeVariants())));
            $result[$node->getContextPath()] = [
                'policy' => $this->nodePolicyService->getNodePolicyInformation($node),
                'dimensions' => $this->getCurrentDimensionPresetIdentifiersForNode($node),
                'otherNodeVariants' => $otherNodeVariants
            ];
        }

        $this->view->assign('value', $result);
    }

    /**
     * Gets an array of current preset identifiers for each dimension of the give node
     *
     * @param NodeInterface $node
     * @return array
     */
    protected function getCurrentDimensionPresetIdentifiersForNode($node)
    {
        $targetPresets = $this->contentDimensionsPresetSource->findPresetsByTargetValues($node->getDimensions());
        $presetCombo = [];
        foreach ($targetPresets as $dimensionName => $presetConfig) {
            $fullPresetConfig = $this->contentDimensionsPresetSource->findPresetByDimensionValues($dimensionName, $presetConfig['values']);
            $presetCombo[$dimensionName] = $fullPresetConfig['identifier'];
        }
        return $presetCombo;
    }

    /**
     * Build and execute a flow query chain
     *
     * @param array $chain
     * @return string
     */
    public function flowQueryAction(array $chain)
    {
        $createContext = array_shift($chain);
        $finisher = array_pop($chain);

        $flowQuery = new FlowQuery(array_map(
            function ($envelope) {
                return $this->nodeService->getNodeFromContextPath($envelope['$node']);
            },
            $createContext['payload']
        ));

        foreach ($chain as $operation) {
            $flowQuery = call_user_func_array([$flowQuery, $operation['type']], $operation['payload']);
        }

        $nodeInfoHelper = new NodeInfoHelper();
        $result = [];
        switch ($finisher['type']) {
            case 'get':
                $result = $nodeInfoHelper->renderNodes($flowQuery->get(), $this->getControllerContext());
            break;
            case 'getForTree':
                $result = $nodeInfoHelper->renderNodes($flowQuery->get(), $this->getControllerContext(), true);
            break;
            case 'getForTreeWithParents':
                $result = $nodeInfoHelper->renderNodesWithParents($flowQuery->get(), $this->getControllerContext());
            break;
        }

        return json_encode($result);
    }
}
