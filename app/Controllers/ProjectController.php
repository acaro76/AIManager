<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Memory\MemoryType;
use App\Models\Memory;
use App\Models\Project;
use App\Models\ProviderConfig;
use App\Models\ChatAttachment;
use App\Services\ChatAttachmentService;
use App\Services\ProjectLifecycleService;

final class ProjectController extends BaseController
{
    public function index(Request $request): void
    {
        $this->view('projects/index', [
            'title' => 'Progetti',
            'activeProjects' => (new Project())->active(),
            'archivedProjects' => (new Project())->archived(),
            'selectedTab' => (string) $request->input('tab', 'active') === 'archived' ? 'archived' : 'active',
        ]);
    }

    public function create(Request $request): void
    {
        $providerConfig = new ProviderConfig();
        $this->view('projects/form', [
            'title' => 'Nuovo progetto',
            'project' => null,
            'providers' => $providerConfig->all(),
            'defaultProvider' => $providerConfig->defaultKey(),
        ]);
    }

    public function show(Request $request): void
    {
        (new WorkspaceController($this->app))->show($request);
    }

    public function edit(Request $request): void
    {
        $project = (new Project())->find((int) $request->input('id'));
        if (!$project) {
            Response::redirect('/projects');
        }

        $providerConfig = new ProviderConfig();
        $memory = new Memory();
        $this->view('projects/form', [
            'title' => 'Impostazioni progetto',
            'project' => $project,
            'providers' => $providerConfig->all(),
            'defaultProvider' => $providerConfig->defaultKey(),
            'memoryTypes' => MemoryType::all(),
            'memories' => $memory->forProject((int) $project['id'], 20),
        ]);
    }

    public function store(Request $request): never
    {
        $this->guard($request);
        (new Project())->create($this->payload($request));
        $this->flash('Progetto creato.', '/projects');
    }

    public function update(Request $request): never
    {
        $this->guard($request);
        $project = (new Project())->find((int) $request->input('id'));
        if (!$project || Project::isArchived($project)) {
            $this->flash('Il progetto archiviato e\' in sola lettura. Ripristinalo per modificarlo.', '/projects?tab=archived');
        }
        (new Project())->update((int) $project['id'], $this->payload($request));
        $this->flash('Progetto aggiornato.', '/projects');
    }

    public function archive(Request $request): never
    {
        $this->guard($request);
        $project = (new Project())->find((int) $request->input('id'));
        if (!$project || (int) ($project['is_system'] ?? 0) === 1) {
            $this->flash('Progetto non trovato.', '/projects');
        }
        if (Project::isArchived($project)) {
            $this->flash('Il progetto e\' gia archiviato. Puoi consultarlo o ripristinarlo.', '/projects?tab=archived');
        }

        try {
            $result = (new ProjectLifecycleService($this->app))->archive($project);
        } catch (\Throwable $exception) {
            $this->flash('Archiviazione non completata: ' . $exception->getMessage(), '/projects/edit?id=' . (int) $project['id']);
        }

        $this->flash(sprintf(
            'Progetto archiviato. %d sessioni consolidate: %d nuove, %d gia note, %d conflitti.',
            $result['sessions'], $result['new'], $result['known'], $result['conflicts']
        ), '/projects?tab=archived');
    }

    public function restore(Request $request): never
    {
        $this->guard($request);
        $project = (new Project())->find((int) $request->input('id'));
        if (!$project || (int) ($project['is_system'] ?? 0) === 1) {
            $this->flash('Progetto non trovato.', '/projects?tab=archived');
        }
        if (!Project::isArchived($project)) {
            $this->flash('Il progetto e\' gia attivo.', '/projects');
        }
        (new ProjectLifecycleService($this->app))->restore($project);
        $this->flash('Progetto ripristinato.', '/projects/show?id=' . (int) $project['id']);
    }

    public function delete(Request $request): never
    {
        $this->guard($request);
        $projectId = (int) $request->input('id');
        $project = (new Project())->find($projectId);
        if ($project && (int) ($project['is_system'] ?? 0) === 0) {
            // SELECT esplicite prima del DELETE: dopo il cascade i percorsi non sarebbero
            // piu' recuperabili. I file esistenti non referenziati restano fuori scope.
            $paths = (new ChatAttachment())->pathsForProject($projectId);

            (new Project())->delete($projectId);
            $files = new ChatAttachmentService($this->app->config['paths']['storage']);
            foreach (array_unique($paths) as $path) {
                $files->deleteStoredFile((string) $path);
            }
        }
        $this->flash('Progetto eliminato.', '/projects');
    }

    private function payload(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')),
            'status' => 'active',
            'provider' => (string) $request->input('provider', (new ProviderConfig())->defaultKey()),
            'system_prompt' => trim((string) $request->input('system_prompt')),
        ];
    }
}
