<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Models\Project;
use App\Models\Session;

/** Archiviazione/ripristino del progetto, separati dalle normali impostazioni. */
final class ProjectLifecycleService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Consolida tutte le sessioni attive; solo dopo il successo marca sessioni e progetto
     * come archiviati in una singola transazione DB.
     *
     * @return array{sessions: int, new: int, known: int, conflicts: int}
     */
    public function archive(array $project): array
    {
        if (Project::isArchived($project)) {
            return ['sessions' => 0, 'new' => 0, 'known' => 0, 'conflicts' => 0];
        }

        $sessionModel = new Session();
        $sessions = $sessionModel->activeForProject((int) $project['id']);
        $brainService = new ProjectBrainService(BrainConsolidationService::fromRoot($this->app->root));
        $summary = ['sessions' => 0, 'new' => 0, 'known' => 0, 'conflicts' => 0];

        foreach ($sessions as $session) {
            $brain = $brainService->analyzeSession($project, $session);
            $summary['sessions']++;
            $summary['new'] += (int) ($brain['new'] ?? 0);
            $summary['known'] += (int) ($brain['known'] ?? 0);
            $summary['conflicts'] += (int) ($brain['conflicts'] ?? 0);
        }

        $this->app->db->transaction(function () use ($sessions, $project): void {
            $sessionModel = new Session();
            foreach ($sessions as $session) {
                $sessionModel->archive((int) $session['id']);
            }
            (new Project())->setStatus((int) $project['id'], 'archived');
        });

        return $summary;
    }

    /** Ripristina il progetto e garantisce che esista una sessione operativa. */
    public function restore(array $project): void
    {
        $this->app->db->transaction(function () use ($project): void {
            $projectId = (int) $project['id'];
            (new Project())->setStatus($projectId, 'active');
            $sessions = new Session();
            if ($sessions->activeForProject($projectId) === []) {
                $sessions->create([
                    'project_id' => $projectId,
                    'title' => 'Sessione di ripresa',
                    'description' => 'Sessione creata al ripristino del progetto.',
                ]);
            }
        });
    }
}
