<?php
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
class theme_builder extends controller
{
    /**
     * Display Theme Builder administration.
     *
     * @param array $params Route parameters.
     *
     * @return void
     */
    public function admin($params = []): void
    {
        $this->require_admin(9);

        require_once USERROOT . '/modules/theme_builder/lib/theme_package_builder.php';

        $builder = new theme_package_builder();
        $message = null;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->require_csrf();

            try {
                $message = $this->handleAction($builder);
            } catch (Throwable $exception) {
                http_response_code(400);
                $error = $exception->getMessage();
            }
        }

        $selected = trim((string) ($_REQUEST['project'] ?? ''));
        $selected = $builder->isValidSlug($selected) ? $selected : '';

        $path = trim((string) ($_REQUEST['path'] ?? ''));
        $content = null;

        if ($selected !== '' && $path !== '') {
            try {
                $content = $builder->readFile($selected, $path);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $this->view(
            'admin/index',
            [
                'projects' => $builder->listProjects(),
                'selected' => $selected,
                'tree' => $selected !== '' ? $builder->fileTree($selected) : [],
                'path' => $path,
                'content' => $content,
                'validation' => $selected !== '' ? $builder->validateProject($selected) : null,
                'artifacts' => $selected !== '' ? $builder->listArtifacts($selected) : [],
                'certification' => $builder->certificationStatus(),
                'message' => $message,
                'error' => $error,
                'csrf_field' => $this->csrf_field(),
            ]
        );
    }

    /**
     * Execute an approved Theme Builder action.
     *
     * @param theme_package_builder $builder Theme package builder.
     *
     * @return string
     */
    private function handleAction(theme_package_builder $builder): string
    {
        $action = (string) ($_POST['action'] ?? '');
        $project = (string) ($_POST['project'] ?? '');

        switch ($action) {
            case 'create_project':
                $builder->createProject($_POST);
                return 'Theme project created.';

            case 'edit_project':
                $builder->editProject($project, $_POST);
                return 'Theme project updated.';

            case 'delete_project':
                $builder->deleteProject($project);
                $_REQUEST['project'] = '';
                return 'Theme project deleted.';

            case 'save_file':
                $builder->writeFile(
                    $project,
                    (string) $_POST['path'],
                    (string) $_POST['content']
                );
                return 'File saved.';

            case 'create_file':
                $builder->createFile($project, (string) $_POST['path']);
                return 'File created.';

            case 'create_directory':
                $builder->createDirectory($project, (string) $_POST['path']);
                return 'Directory created.';

            case 'rename_path':
                $builder->renamePath(
                    $project,
                    (string) $_POST['path'],
                    (string) $_POST['new_path']
                );
                return 'Path renamed.';

            case 'delete_path':
                $builder->deletePath($project, (string) $_POST['path']);
                return 'Path deleted.';

            case 'build_release':
                return 'Unsigned theme release built: '
                    . basename($builder->buildRelease($project));

            case 'sign_release':
                $builder->signRelease(
                    $project,
                    (string) $_POST['artifact'],
                    $_FILES['private_key'] ?? []
                );
                return 'Theme release signed; key not retained.';
        }

        throw new InvalidArgumentException('Unknown action.');
    }
}
/* [End AI:GPT-5.6 Sol] */
