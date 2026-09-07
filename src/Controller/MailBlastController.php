<?php
namespace GlpiPlugin\Mailblast\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Mailblast\Service\ActionService;
use GlpiPlugin\Mailblast\Service\ConfigurationService;
use GlpiPlugin\Mailblast\Service\ContentService;
use GlpiPlugin\Mailblast\Service\ReportService;
use GlpiPlugin\Mailblast\Service\RecipientService;
use GlpiPlugin\Mailblast\Service\ViewService;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MailBlastController extends AbstractController
{
    public function __construct(
        private readonly UrlGeneratorInterface $router
    ) {
    }

    #[Route('/Send', name: 'mailblast_send', methods: 'GET')]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function send(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        $viewService = new ViewService();
        return $this->render('@mailblast/send.html.twig', $viewService->getSendViewData(
            $this->generatePluginUrl('mailblast_action', $request),
            $this->generatePluginUrl('mailblast_configuration', $request),
            $this->generatePluginUrl('mailblast_report', $request)
        ));
    }

    #[Route('/Configuration', name: 'mailblast_configuration', methods: ['GET', 'POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function configuration(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        $configurationService = new ConfigurationService();
        $recipientService = new RecipientService();
        $saved = false;
        $errors = [];

        if ($request->isMethod('POST')) {
            $batchSize = $request->request->getInt('batch_size', 15);
            $batchDelay = $request->request->getInt('batch_delay_ms', 120);
            $maxAttachment = $request->request->getInt('max_attachment_mb', 15);
            $historyLimit = $request->request->getInt('history_limit', 10);
            if ($batchSize < 1 || $batchSize > 100) {
                $errors[] = __('Batch size must be between 1 and 100.', 'mailblast');
            }
            if ($batchDelay < 0 || $batchDelay > 5000) {
                $errors[] = __('Batch delay must be between 0 and 5000 ms.', 'mailblast');
            }
            if ($maxAttachment < 1 || $maxAttachment > 100) {
                $errors[] = __('Maximum attachment size must be between 1 and 100 MB.', 'mailblast');
            }
            if ($historyLimit < 10 || $historyLimit > 100) {
                $errors[] = __('History limit must be between 10 and 100.', 'mailblast');
            }
            if ($errors === []) {
                $configurationService->saveSettings($batchSize, $batchDelay, $maxAttachment, $historyLimit);
                $saved = true;
            }
        }

        return $this->render('@mailblast/config.html.twig', [
            'title' => __('Mail Blast — Configuration', 'mailblast'),
            'menu' => ['admin', 'plugin', 'mailblast'],
            'saved' => $saved,
            'errors' => $errors,
            'batch_size' => $configurationService->getBatchSize(),
            'batch_delay' => $configurationService->getBatchDelayMs(),
            'max_attachment' => $configurationService->getMaxAttachmentMb(),
            'history_limit' => $configurationService->getHistoryLimit(),
            'user_count' => $recipientService->countActiveUsersWithEmail(),
            'history' => $configurationService->getHistory(),
            'timezone' => date_default_timezone_get(),
            'send_url' => $this->generatePluginUrl('mailblast_send', $request),
            'config_url' => $this->generatePluginUrl('mailblast_configuration', $request),
            'csrf_token' => Session::getNewCSRFToken(),
            'save_label' => _sx('button', 'Save'),
            'version_check' => $configurationService->checkLatestVersion(),
        ]);
    }

    #[Route('/ajax/Action', name: 'mailblast_action', methods: ['GET', 'POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function action(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        $service = new ActionService();
        $action = (string) $request->request->get('action', $request->query->get('action', ''));

        if ($request->isMethod('GET') && $action === 'count_recipients') {
            $filterType = (string) $request->query->get('filter_type', 'all');
            $filterIds = json_decode((string) $request->query->get('filter_ids', '[]'), true) ?? [];
            return new JsonResponse([
                'ok' => true,
                'count' => $service->countRecipients($filterType, is_array($filterIds) ? $filterIds : []),
            ]);
        }

        if (!$request->isMethod('POST')) {
            return $this->jsonError(__('Invalid request method.', 'mailblast'), 405);
        }

        $subject = trim(strip_tags((string) $request->request->get('subject', '')));
        $body = (string) $request->request->get('body', '');
        $footer = (new ContentService())->sanitizeFooter(
            (string) $request->request->get('footer', '')
        );
        $replyToUserId = max(0, $request->request->getInt('reply_to_user_id', 0));

        try {
            return match ($action) {
                'test_send' => $this->respond($service->testSend(
                    $subject,
                    $body,
                    $footer,
                    (string) $request->request->get('test_mode', 'my_address'),
                    (string) $request->request->get('test_email', ''),
                    json_decode((string) $request->request->get('attachments_b64', '[]'), true) ?: [],
                    $replyToUserId
                )),
                'queue_init' => $this->respond($service->initializeQueue(
                    $subject,
                    $body,
                    $footer,
                    json_decode((string) $request->request->get('attachments_b64', '[]'), true) ?: [],
                    (string) $request->request->get('filter_type', 'all'),
                    json_decode((string) $request->request->get('filter_ids', '[]'), true) ?: [],
                    $replyToUserId
                )),
                'queue_process' => $this->respond($service->processQueue(
                    trim((string) $request->request->get('send_id', '')),
                    $request->request->getInt('offset', 0),
                    (string) $request->request->get('html', ''),
                    (string) $request->request->get('plain', ''),
                    json_decode((string) $request->request->get('attachments_b64', '[]'), true) ?: [],
                    json_decode((string) $request->request->get('inline_images_b64', '[]'), true) ?: []
                )),
                default => $this->jsonError(__('Unknown action.', 'mailblast'), 400),
            };
        } catch (\Throwable $e) {
            \Toolbox::logInFile(
                'mailblast',
                sprintf("Action '%s' failed: %s\n", $action, $e->getMessage()),
                true
            );
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    #[Route('/ajax/Report', name: 'mailblast_report', methods: ['GET', 'POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function report(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['ok' => false, 'error' => __('Invalid request method.', 'mailblast')], 405);
        }
        try {
            $rows = json_decode((string) $request->request->get('rows', '[]'), true) ?? [];
            if (!is_array($rows)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => __('Invalid report data.', 'mailblast'),
                    'csrf' => Session::getNewCSRFToken(),
                ], 400);
            }

            $subject = trim(strip_tags((string) $request->request->get('subject', '')));
            $report = (new ReportService())->generate($rows, $subject);

            return new JsonResponse([
                'ok' => true,
                'data' => $report['data'],
                'filename' => $report['filename'],
                'csrf' => Session::getNewCSRFToken(),
            ]);
        } catch (\Throwable $e) {
            Toolbox::logInFile(
                'mailblast',
                sprintf("Report generation failed: %s\n", $e->getMessage()),
                true
            );

            return new JsonResponse([
                'ok' => false,
                'error' => $e->getMessage(),
                'csrf' => Session::getNewCSRFToken(),
            ], 500);
        }
    }

    /**
     * Generate a URL for another Mail Blast route.
     *
     * GLPI adds a name prefix to plugin routes when they are loaded. The
     * current request already contains the fully-qualified route name, so use
     * its namespace instead of guessing or hard-coding the plugin location.
     */
    private function generatePluginUrl(string $route, Request $request): string
    {
        $currentRoute = (string) $request->attributes->get('_route', '');
        $prefix = '';

        if (($separator = strpos($currentRoute, ':')) !== false) {
            $prefix = substr($currentRoute, 0, $separator + 1);
        } else {
            // Attribute routes are registered under a plugin-specific prefix
            // by GLPI. The canonical public plugin namespace is /plugins/.
            $prefix = '@mailblast:';
        }

        return $this->router->generate($prefix . $route);
    }

    private function respond(array $payload): Response
    {
        $status = ($payload['ok'] ?? false) ? 200 : 400;
        $payload['csrf'] = Session::getNewCSRFToken();
        return new JsonResponse($payload, $status);
    }

    private function jsonError(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => $message,
            'csrf' => Session::getNewCSRFToken(),
        ], $status);
    }

}
