<?php

/**
 * @file plugins/generic/ojsbrServices/pages/OjsbrEditorHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief The screen managers and editors use: lists the submissions, opens a
 *        service order on the connector and uploads the files.
 */

namespace APP\plugins\generic\ojsbrServices\pages;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\ojsbrServices\OjsbrServicesPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\CsrfPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;

class OjsbrEditorHandler extends Handler
{
    public function __construct(protected OjsbrServicesPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            OjsbrServicesPlugin::EDITOR_OPS
        );
    }

    /**
     * @param Request $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new UserRequiredPolicy($request));
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        if ($request->isPost()) {
            $this->addPolicy(new CsrfPolicy($request));
        }
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * The submissions, each with the service order already recorded for it.
     */
    /**
     * @copydoc PKPHandler::index()
     *
     * The signature is the parent's: PHP refuses a narrower one, and the page
     * would fatal before anything is rendered.
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $rows = $this->listSubmissionRows($contextId);
        $osRefs = $this->plugin->getOsRefs($contextId);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.ojsbrServices.editor.title'),
            'pluginPageUrl' => $this->pageUrl($request, 'index'),
            'criarUrl' => $this->pageUrl($request, 'criar'),
            'statusUrl' => $this->pageUrl($request, 'status'),
            'pollUrl' => $this->pageUrl($request, 'poll'),
            'osUrl' => $this->pageUrl($request, 'os'),
            'rows' => $rows,
            'osRefs' => $osRefs,
            'hasSettings' => $this->plugin->getConnectorUrl($contextId) !== '' && $this->plugin->getPluginToken($contextId) !== '',
            'flash' => (string) $request->getUserVar('flash'),
            'flashNumero' => (string) $request->getUserVar('numero'),
            'flashFaltante' => (string) $request->getUserVar('faltante'),
        ]);
        $templateMgr->display($this->plugin->getTemplateResource('editor.tpl'));
    }

    /**
     * POST: builds the create payload, calls the connector and uploads the
     * files one after another.
     */
    public function criar(array $args, Request $request): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();

        if ($this->plugin->getConnectorUrl($contextId) === '' || $this->plugin->getPluginToken($contextId) === '') {
            $this->redirectFlash($request, 'missingSettings');
        }

        $ids = $request->getUserVar('submissionIds');
        if (!is_array($ids) || $ids === []) {
            $this->redirectFlash($request, 'missingItems');
        }

        $built = $this->buildCreatePayload($request, array_map('intval', $ids));
        $response = $this->plugin->callConnector($contextId, 'POST', '/plugin/v1/ordens', $built['json']);
        if (empty($response['signed'])) {
            $this->redirectFlash($request, 'signatureFailed');
        }
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || !is_array($response['json'])) {
            $this->redirectFlash($request, 'badRequest');
        }

        $data = $response['json'];
        $numero = (string) ($data['numero'] ?? '');
        if ($numero === '') {
            $this->redirectFlash($request, 'badRequest');
        }

        foreach ($built['uploads'] as $submissionId => $files) {
            $path = '/plugin/v1/ordens/' . rawurlencode($numero) . '/itens/' . rawurlencode((string) $submissionId) . '/arquivos';
            $uploadErro = false;
            foreach ($files as $file) {
                $upload = $this->plugin->callConnector($contextId, 'POST', $path, null, [$file]);
                if (empty($upload['signed']) || ($upload['status'] ?? 0) >= 400) {
                    $uploadErro = true;
                    break;
                }
            }
            $fresh = $this->plugin->callConnector(
                $contextId,
                'GET',
                '/plugin/v1/ordens/' . rawurlencode($numero)
            );
            $freshData = (!empty($fresh['signed']) && is_array($fresh['json'])) ? $fresh['json'] : $data;
            $this->persistOsFromPayload($contextId, $freshData, $numero, 'criar');
            $this->plugin->persistOsRef($contextId, (string) $submissionId, [
                'numero' => $numero,
                'origem' => 'criar',
                'uploadErro' => $uploadErro,
                'situacaoProducao' => $freshData['situacaoProducao'] ?? null,
                'situacaoFinanceira' => $freshData['situacaoFinanceira'] ?? null,
                'creditoFaltante' => $freshData['creditoFaltante'] ?? null,
            ]);
        }

        foreach ($built['json']['items'] as $item) {
            $sid = (string) $item['submissionId'];
            if (!isset($this->plugin->getOsRefs($contextId)[$sid])) {
                $this->plugin->persistOsRef($contextId, $sid, [
                    'numero' => $numero,
                    'origem' => 'criar',
                    'situacaoProducao' => $data['situacaoProducao'] ?? null,
                    'situacaoFinanceira' => $data['situacaoFinanceira'] ?? null,
                ]);
            }
        }

        $url = $this->pageUrl($request, 'os') . '?' . http_build_query([
            'numero' => $numero,
            'flash' => 'created',
            'faltante' => (string) ($data['creditoFaltante'] ?? ''),
        ]);
        $request->redirectUrl($url);
        exit;
    }

    /**
     * POST: asks the connector for the order (a signed answer) and updates what
     * is recorded for the submission.
     */
    public function status(array $args, Request $request): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $numero = trim((string) $request->getUserVar('numero'));
        if ($numero === '') {
            $this->redirectFlash($request, 'missingItems');
        }

        $response = $this->plugin->callConnector(
            $contextId,
            'GET',
            '/plugin/v1/ordens/' . rawurlencode($numero)
        );
        if (empty($response['signed']) || !is_array($response['json'])) {
            $this->redirectFlash($request, 'signatureFailed');
        }

        $data = $response['json'];
        $this->persistOsFromPayload($contextId, $data, $numero, 'status');
        $url = $this->pageUrl($request, 'os') . '?' . http_build_query([
            'numero' => $data['numero'] ?? $numero,
            'flash' => 'created',
            'faltante' => (string) ($data['creditoFaltante'] ?? ''),
        ]);
        $request->redirectUrl($url);
        exit;
    }

    /**
     * The screen of one service order: only this screen polls, never the list.
     */
    public function os(array $args, Request $request): void
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $numero = trim((string) $request->getUserVar('numero'));
        if ($numero === '') {
            $this->redirectFlash($request, 'missingItems');
        }

        $response = $this->plugin->callConnector(
            $contextId,
            'GET',
            '/plugin/v1/ordens/' . rawurlencode($numero)
        );
        if (empty($response['signed']) || !is_array($response['json'])) {
            $this->redirectFlash($request, 'signatureFailed');
        }

        $data = $response['json'];
        $this->persistOsFromPayload($contextId, $data, $numero, 'os');

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.ojsbrServices.editor.osTitle', ['numero' => $data['numero'] ?? $numero]),
            'pluginPageUrl' => $this->pageUrl($request, 'index'),
            'pollUrl' => $this->pageUrl($request, 'poll'),
            'os' => $data,
            'numero' => $data['numero'] ?? $numero,
            'flash' => (string) $request->getUserVar('flash'),
            'flashFaltante' => (string) $request->getUserVar('faltante'),
        ]);
        $templateMgr->display($this->plugin->getTemplateResource('os.tpl'));
    }

    /**
     * The JSON the screen polls while it is open. No CSRF token: it only reads.
     */
    public function poll(array $args, Request $request): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $numero = trim((string) $request->getUserVar('numero'));
        if ($numero === '') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false]);
            exit;
        }
        $response = $this->plugin->callConnector(
            $contextId,
            'GET',
            '/plugin/v1/ordens/' . rawurlencode($numero)
        );
        if (empty($response['signed']) || !is_array($response['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false]);
            exit;
        }
        $data = $response['json'];
        $this->persistOsFromPayload($contextId, $data, $numero, 'poll');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'numero' => $data['numero'] ?? $numero,
            'situacaoProducao' => $data['situacaoProducao'] ?? null,
            'situacaoFinanceira' => $data['situacaoFinanceira'] ?? null,
            'creditoFaltante' => $data['creditoFaltante'] ?? 0,
            'itens' => $data['itens'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function persistOsFromPayload(int $contextId, array $data, string $numero, string $origem): void
    {
        foreach (($data['itens'] ?? []) as $item) {
            $sid = (string) ($item['submissionId'] ?? '');
            if ($sid === '') {
                continue;
            }
            $this->plugin->persistOsRef($contextId, $sid, [
                'numero' => $data['numero'] ?? $numero,
                'origem' => $origem,
                'situacaoProducao' => $data['situacaoProducao'] ?? null,
                'situacaoFinanceira' => $data['situacaoFinanceira'] ?? null,
                'itemStatus' => $item['status'] ?? ($item['situacaoProducao'] ?? null),
                'creditoFaltante' => $data['creditoFaltante'] ?? null,
            ]);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listSubmissionRows(int $contextId): array
    {
        $osRefs = $this->plugin->getOsRefs($contextId);
        $collector = Repo::submission()->getCollector()->filterByContextIds([$contextId]);
        if (method_exists($collector, 'limit')) {
            $collector->limit(150);
        }
        $rows = [];
        foreach ($collector->getMany() as $submission) {
            if (!$submission instanceof Submission) {
                continue;
            }
            $publication = $submission->getCurrentPublication();
            $sid = (string) $submission->getId();
            $rows[] = [
                'submissionId' => $sid,
                'publicationId' => $publication?->getId(),
                'title' => $this->publicationTitle($submission, $publication),
                'doi' => $publication ? $this->publicationDoi($publication) : '',
                'os' => $osRefs[$sid] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * @param int[] $submissionIds
     * @return array{json:array<string,mixed>,uploads:array<int|string,list<array<string,mixed>>>}
     */
    private function buildCreatePayload(Request $request, array $submissionIds): array
    {
        $context = $request->getContext();
        $items = [];
        $uploads = [];

        foreach ($submissionIds as $submissionId) {
            $submission = Repo::submission()->get($submissionId);
            if (!$submission instanceof Submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
                continue;
            }
            $publication = $submission->getCurrentPublication();
            $built = $this->buildItem($submission, $publication);
            $items[] = $built['item'];
            $uploads[$submission->getId()] = $built['files'];
        }

        $locales = method_exists($context, 'getSupportedLocales')
            ? array_values($context->getSupportedLocales())
            : [];

        return [
            'json' => [
                'service' => 'OS_JATS_XML',
                'ojsVersion' => '3.5',
                'journalPath' => (string) $context->getPath(),
                'journal' => [
                    'title' => (string) $context->getLocalizedName(),
                    'acronym' => (string) (method_exists($context, 'getLocalizedAcronym') ? $context->getLocalizedAcronym() : ''),
                    'issnPrint' => (string) $context->getData('printIssn'),
                    'issnOnline' => (string) $context->getData('onlineIssn'),
                    'publisher' => (string) $context->getData('publisherInstitution'),
                    'locales' => $locales,
                    'metadata' => [],
                ],
                'items' => $items,
            ],
            'uploads' => $uploads,
        ];
    }

    /**
     * @return array{item:array<string,mixed>,files:list<array<string,mixed>>}
     */
    private function buildItem(Submission $submission, ?Publication $publication): array
    {
        $galleysMeta = [];
        $filesMeta = [];
        $uploads = [];
        $seenNames = [];

        foreach ($this->publicationGalleys($publication) as $galley) {
            $galleyId = (string) $galley->getId();
            $locale = (string) ($galley->getLocale() ?: $submission->getData('locale') ?: '');
            $file = $this->galleyFile($galley);
            $fileName = $file ? $this->fileName($file) : ($galley->getLabel() ?: 'galley');
            $galleysMeta[] = [
                'id' => $galleyId,
                'label' => (string) $galley->getLabel(),
                'locale' => $locale,
                'genre' => 'galley',
                'fileName' => $fileName,
            ];
            $filesMeta[] = [
                'role' => 'galley',
                'galleyId' => $galleyId,
                'fileName' => $fileName,
                'locale' => $locale,
            ];
            if ($file && $this->isPdf($fileName)) {
                $filesMeta[] = [
                    'role' => 'pdf_final',
                    'fileName' => $fileName,
                    'locale' => $locale,
                ];
            }
            $bytes = $file ? $this->readFileBytes($file) : null;
            if ($bytes !== null) {
                $uploads[] = [
                    'role' => 'galley',
                    'galleyId' => $galleyId,
                    'fileName' => $fileName,
                    'locale' => $locale,
                    'contents' => $bytes,
                ];
                if ($this->isPdf($fileName)) {
                    $uploads[] = [
                        'role' => 'pdf_final',
                        'fileName' => $fileName,
                        'locale' => $locale,
                        'contents' => $bytes,
                    ];
                }
                $seenNames[$fileName] = true;
            }
        }

        foreach ($this->submissionFiles($submission) as $file) {
            $fileName = $this->fileName($file);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['doc', 'docx', 'pdf'], true)) {
                continue;
            }
            if (isset($seenNames[$fileName]) && $ext === 'pdf') {
                continue;
            }
            $role = $ext === 'pdf' ? 'pdf_final' : 'manuscrito';
            $already = array_filter($filesMeta, static fn ($f) => ($f['role'] ?? '') === $role);
            if ($already && $role === 'pdf_final') {
                continue;
            }
            $filesMeta[] = [
                'role' => $role,
                'fileName' => $fileName,
            ];
            $bytes = $this->readFileBytes($file);
            if ($bytes !== null) {
                $uploads[] = [
                    'role' => $role,
                    'fileName' => $fileName,
                    'contents' => $bytes,
                ];
            }
        }

        return [
            'item' => [
                'submissionId' => (string) $submission->getId(),
                'publicationId' => $publication ? (string) $publication->getId() : null,
                'title' => $this->publicationTitle($submission, $publication),
                'doi' => $publication ? $this->publicationDoi($publication) : '',
                'locale' => (string) ($publication?->getData('locale') ?: $submission->getData('locale') ?: ''),
                'metadata' => [],
                'galleys' => $galleysMeta,
                'files' => $filesMeta,
            ],
            'files' => $uploads,
        ];
    }

    /**
     * @return list<object>
     */
    private function publicationGalleys(?Publication $publication): array
    {
        if (!$publication) {
            return [];
        }
        $galleys = $publication->getData('galleys');
        if (is_array($galleys) || $galleys instanceof \Traversable) {
            return array_values(is_array($galleys) ? $galleys : iterator_to_array($galleys));
        }
        if (method_exists(Repo::class, 'galley')) {
            try {
                return array_values(iterator_to_array(
                    Repo::galley()->getCollector()
                        ->filterByPublicationIds([$publication->getId()])
                        ->getMany()
                ));
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    private function galleyFile(object $galley): ?SubmissionFile
    {
        if (method_exists($galley, 'getFile')) {
            $file = $galley->getFile();
            if ($file instanceof SubmissionFile) {
                return $file;
            }
        }
        $fileId = method_exists($galley, 'getData') ? $galley->getData('submissionFileId') : null;
        if ($fileId) {
            $file = Repo::submissionFile()->get((int) $fileId);
            return $file instanceof SubmissionFile ? $file : null;
        }
        return null;
    }

    /**
     * @return list<SubmissionFile>
     */
    private function submissionFiles(Submission $submission): array
    {
        $stages = [
            SubmissionFile::SUBMISSION_FILE_SUBMISSION,
            SubmissionFile::SUBMISSION_FILE_FINAL,
            SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
        ];
        try {
            return array_values(iterator_to_array(
                Repo::submissionFile()->getCollector()
                    ->filterBySubmissionIds([$submission->getId()])
                    ->filterByFileStages($stages)
                    ->getMany()
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private function fileName(SubmissionFile $file): string
    {
        $name = $file->getData('name');
        if (is_array($name)) {
            $name = $file->getLocalizedData('name') ?: reset($name);
        }
        return (string) ($name ?: ('file-' . $file->getId()));
    }

    private function isPdf(string $fileName): bool
    {
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function readFileBytes(SubmissionFile $file): ?string
    {
        try {
            $path = (string) $file->getData('path');
            if ($path === '') {
                return null;
            }
            $fileService = app()->get('file');
            if ($fileService && isset($fileService->fs) && method_exists($fileService->fs, 'read')) {
                $bytes = $fileService->fs->read($path);
                return is_string($bytes) ? $bytes : null;
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /**
     * The title of a submission, which since 3.4 lives on its publication.
     */
    private function publicationTitle(Submission $submission, ?Publication $publication): string
    {
        $publication ??= $submission->getCurrentPublication();
        if (!$publication) {
            return '';
        }
        $title = (string) $publication->getLocalizedFullTitle();

        return $title !== '' ? $title : (string) $publication->getLocalizedTitle();
    }

    private function publicationDoi(Publication $publication): string
    {
        if (method_exists($publication, 'getDoi')) {
            $doi = $publication->getDoi();
            if ($doi) {
                return (string) $doi;
            }
        }
        return (string) ($publication->getStoredPubId('doi') ?: '');
    }

    /**
     * @param array<string,string> $params
     */
    private function redirectFlash(Request $request, string $flash, array $params = []): never
    {
        $url = $this->pageUrl($request, 'index') . '?' . http_build_query(array_merge(['flash' => $flash], $params));
        $request->redirectUrl($url);
        exit;
    }

    private function pageUrl(Request $request, string $op): string
    {
        $context = $request->getContext();
        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context?->getPath(),
            OjsbrServicesPlugin::PAGE_NAME,
            $op
        );
    }
}
