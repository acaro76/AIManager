<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Memory\MemoryType;
use App\Models\Memory;
use App\Models\Project;
use App\Services\ChatAttachmentService;

final class MemoryController extends BaseController
{
    public function index(Request $request): void
    {
        $projects = (new Project())->all();
        $selectedProject = (int) $request->input('project_id', $projects[0]['id'] ?? 0);
        $selectedProjectRow = null;
        foreach ($projects as $project) {
            if ((int) $project['id'] === $selectedProject) {
                $selectedProjectRow = $project;
                break;
            }
        }
        $query = trim((string) $request->input('q', ''));
        $memory = new Memory();
        $this->view('memory/index', [
            'title' => 'Knowledge',
            'query' => $query,
            'selectedProject' => $selectedProject,
            'selectedProjectArchived' => $selectedProjectRow !== null && Project::isArchived($selectedProjectRow),
            'memoryTypes' => MemoryType::all(),
            'memories' => $selectedProject > 0
                ? ($query === '' ? $memory->forProject($selectedProject, 50) : $memory->searchForProject($selectedProject, $query))
                : [],
            'projects' => $projects,
            'activeProjects' => (new Project())->active(),
        ]);
    }

    public function store(Request $request): never
    {
        $this->guard($request);
        $projectId = (int) $request->input('project_id', 0);
        $returnTo = $this->safeReturnTo((string) $request->input('return_to', '/memory'));
        $project = $projectId > 0 ? (new Project())->find($projectId) : null;
        if (!$project) {
            $this->flash('Seleziona un progetto valido per salvare memoria.', $returnTo);
        }
        if (Project::isArchived($project)) {
            $this->flash('Il progetto archiviato e\' in sola lettura.', $returnTo);
        }

        $uploaded = $_FILES['memory_file'] ?? null;
        $hasFile = is_array($uploaded) && (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $manualContent = trim((string) $request->input('content'));
        $memoryType = (string) $request->input('memory_type', MemoryType::NOTE);
        $title = trim((string) $request->input('title'));
        $tags = trim((string) $request->input('tags'));
        $importance = (int) $request->input('importance', 3);

        if ($hasFile) {
            $file = (new ChatAttachmentService($this->app->config['paths']['storage']))->ingestMemoryFile($uploaded, $projectId);
            if (!$file) {
                $this->flash('File non valido o troppo grande. Memoria non salvata.', $returnTo);
            }

            $fileText = trim((string) $file['text']);
            if ($fileText === '') {
                if (is_file((string) $file['absolute_path'])) {
                    unlink((string) $file['absolute_path']);
                }
                $this->flash('Testo non estraibile dal file. Memoria non salvata.', $returnTo);
            }

            $content = $manualContent !== ''
                ? $manualContent . "\n\n--- Documento importato: " . $file['name'] . " ---\n" . $fileText
                : $fileText;

            try {
                (new Memory())->createBrainItem([
                    'project_id' => $projectId,
                    'session_id' => (int) $request->input('session_id', 0) ?: null,
                    'memory_type' => $memoryType,
                    'title' => $title !== '' ? $title : (string) $file['name'],
                    'content' => $content,
                    'tags' => $tags,
                    'importance' => $importance,
                    'brain_category' => MemoryType::normalize($memoryType),
                    'canonical_key' => 'memory_file:' . sha1((string) $file['path']),
                    'confidence' => 1,
                    'source' => 'memory_upload',
                    'metadata' => [
                        'file_name' => (string) $file['name'],
                        'file_path' => (string) $file['path'],
                        'file_extension' => (string) $file['extension'],
                        'file_size' => (int) $file['size'],
                        'file_mime' => (string) $file['mime'],
                    ],
                ]);
            } catch (\Throwable $exception) {
                @unlink((string) $file['absolute_path']);
                throw $exception;
            }
            $this->flash('Documento importato nella memoria.', $returnTo);
        }

        if ($manualContent === '') {
            $this->flash('Inserisci un contenuto o carica un documento leggibile.', $returnTo);
        }

        if ($title === '') {
            $this->flash('Inserisci un titolo per salvare la memoria.', $returnTo);
        }

        (new Memory())->create([
            'project_id' => $projectId,
            'session_id' => (int) $request->input('session_id', 0) ?: null,
            'memory_type' => $memoryType,
            'title' => $title,
            'content' => $manualContent,
            'tags' => $tags,
            'importance' => $importance,
        ]);
        $this->flash('Memoria salvata.', $returnTo);
    }

    public function delete(Request $request): never
    {
        $this->guard($request);
        $memory = new Memory();
        $row = $memory->findById((int) $request->input('id'));
        $filePath = '';
        if ($row !== null) {
            $project = (new Project())->find((int) $row['project_id']);
            if ($project && Project::isArchived($project)) {
                $this->flash('Il progetto archiviato e\' in sola lettura.', $this->safeReturnTo((string) $request->input('return_to', '/memory')));
            }
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $filePath = is_array($metadata) ? (string) ($metadata['file_path'] ?? '') : '';
            $memory->delete((int) $row['id']);
        }
        if ($filePath !== '') {
            (new ChatAttachmentService($this->app->config['paths']['storage']))->deleteStoredFile($filePath);
        }
        $this->flash('Memoria eliminata.', $this->safeReturnTo((string) $request->input('return_to', '/memory')));
    }

    public function search(Request $request): never
    {
        $this->guard($request);
        $projectId = (int) $request->input('project_id', 0);
        Response::json([
            'ok' => true,
            'items' => $projectId > 0 ? (new Memory())->searchForProject($projectId, trim((string) $request->input('q'))) : [],
        ]);
    }

    private function safeReturnTo(string $path): string
    {
        $target = $path !== '' ? $path : '/memory';
        $targetPath = parse_url($target, PHP_URL_PATH) ?: '/memory';
        return in_array($targetPath, ['/memory', '/projects/edit'], true) ? $target : '/memory';
    }
}
