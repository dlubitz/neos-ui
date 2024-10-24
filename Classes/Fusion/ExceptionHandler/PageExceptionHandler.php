<?php
namespace Neos\Neos\Ui\Fusion\ExceptionHandler;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ContentStream;
use Neos\Flow\Http\Response;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Utility\Environment;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\Fusion\Core\ExceptionHandlers\AbstractRenderingExceptionHandler;
use Neos\Fusion\Core\ExceptionHandlers\HtmlMessageHandler;
use Neos\Neos\Fusion\ExceptionHandlers\PageHandler;
use Neos\Neos\Ui\Fusion\Helper\ActivationHelper;

/**
 * A page exception handler for the new UI.
 *
 * FIXME: When the old UI is removed this handler needs to be untangled from the PageHandler as the parent functionality is no longer needed.
 * FIXME: We should adapt rendering to requested "format" at some point
 */
class PageExceptionHandler extends AbstractRenderingExceptionHandler
{
    /**
     * @Flow\Inject
     * @var ActivationHelper
     */
    protected $activationHelper;

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * Handle an exception by displaying an error message inside the Neos backend, if logged in and not displaying the live workspace.
     *
     * @param string $fusionPath path causing the exception
     * @param \Exception $exception exception to handle
     * @param integer $referenceCode
     * @return string
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\FluidAdaptor\Exception
     */
    protected function handle($fusionPath, \Exception $exception, $referenceCode)
    {
        if ($this->activationHelper->isLegacyBackendEnabled()) {
            $handler = new PageHandler();
            return $handler->handleRenderingException($fusionPath, $exception);
        }

        $handler = new HtmlMessageHandler($this->environment->getContext()->isDevelopment());
        $handler->setRuntime($this->runtime);
        $output = $handler->handleRenderingException($fusionPath, $exception);
        $fluidView = $this->prepareFluidView();
        $fluidView->assignMultiple([
            'message' => $output
        ]);

        return $this->wrapHttpResponse($exception, $fluidView->render());
    }

    /**
     * Renders an actual HTTP response including the correct status and cache control header.
     *
     * @param \Exception the exception
     * @param string $bodyContent
     * @return string
     */
    protected function wrapHttpResponse(\Exception $exception, string $bodyContent): string
    {
        $body = fopen('php://temp', 'rw');
        fputs($body, $bodyContent);
        rewind($body);

        /** @var Response $response */
        $response = (new Response())
            ->withStatus($exception instanceof FlowException ? $exception->getStatusCode() : 500)
            ->withBody(new ContentStream($body))
            ->withHeader('Cache-Control', 'no-store');

        return implode("\r\n", $response->renderHeaders()) . "\r\n\r\n" . $response->getContent();
    }

    /**
     * Prepare a Fluid view for rendering an error page with the Neos backend
     *
     * @return ViewInterface
     * @throws \Neos\FluidAdaptor\Exception
     */
    protected function prepareFluidView(): ViewInterface
    {
        $fluidView = new StandaloneView();
        $fluidView->setControllerContext($this->runtime->getControllerContext());
        $fluidView->setFormat('html');
        $fluidView->setTemplatePathAndFilename('resource://Neos.Neos.Ui/Private/Templates/Error/ErrorMessage.html');

        $guestNotificationScript = new StandaloneView();
        $guestNotificationScript->setTemplatePathAndFilename('resource://Neos.Neos.Ui/Private/Templates/Backend/GuestNotificationScript.html');
        $fluidView->assign('guestNotificationScript', $guestNotificationScript->render());

        return $fluidView;
    }
}
