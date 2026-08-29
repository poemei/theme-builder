<?php
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
$escape = static fn($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES,
    'UTF-8'
);
$projectMap = [];

foreach ($projects as $project) {
    $projectMap[$project['slug']] = $project;
}

$current = $projectMap[$selected] ?? null;
?>
<main class="container-fluid py-4">
    <div class="d-flex justify-content-between mb-4">
        <div>
            <h1>Theme Builder <small class="text-muted">0.1.0</small></h1>
            <p>
                Live source: <code>user/themes/&lt;slug&gt;/</code>.
                Output: root <code>/releases/&lt;slug&gt;/</code>.
            </p>
        </div>

        <span class="badge <?= $certification['signing'] ? 'bg-success' : 'bg-secondary'; ?> p-2 align-self-start">
            <?= $certification['signing'] ? 'Certified theme signing' : 'Unsigned development'; ?>
        </span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= $escape($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $escape($error); ?></div>
    <?php endif; ?>

    <div class="alert alert-light border">
        <strong>Certification:</strong> <?= $escape($certification['message']); ?>
    </div>

    <div class="row g-4">
        <aside class="col-xl-3">
            <div class="card mb-4">
                <div class="card-header fw-bold">Theme projects</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($projects as $project): ?>
                        <a
                            class="list-group-item list-group-item-action <?= $selected === $project['slug'] ? 'active' : ''; ?>"
                            href="/admin/theme_builder?project=<?= rawurlencode($project['slug']); ?>"
                        >
                            <?= $escape($project['name']); ?><br>
                            <small><?= $escape($project['slug']); ?> / <?= $escape($project['version']); ?></small>
                        </a>
                    <?php endforeach; ?>

                    <?php if (!$projects): ?>
                        <span class="list-group-item text-muted">No theme projects.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-bold">Create theme</div>
                <form method="post" class="card-body">
                    <?= $csrf_field; ?>
                    <input type="hidden" name="action" value="create_project">

                    <label class="form-label">
                        Slug
                        <input
                            class="form-control"
                            name="slug"
                            required
                            pattern="[a-z][a-z0-9_]{1,62}"
                            placeholder="classic"
                        >
                    </label>

                    <label class="form-label">
                        Name
                        <input class="form-control" name="name" required placeholder="Classic">
                    </label>

                    <label class="form-label">
                        Version
                        <input class="form-control" name="version" value="1.0.0" required>
                    </label>

                    <label class="form-label">
                        Description
                        <textarea class="form-control" name="description"></textarea>
                    </label>

                    <button class="btn btn-primary w-100">Create starter theme</button>
                </form>
            </div>
        </aside>

        <section class="col-xl-9">
            <?php if ($current): ?>
                <div class="card mb-4">
                    <div class="card-header fw-bold">Project settings</div>
                    <form method="post" class="card-body row g-3">
                        <?= $csrf_field; ?>
                        <input type="hidden" name="action" value="edit_project">
                        <input type="hidden" name="project" value="<?= $escape($selected); ?>">

                        <div class="col-md-6">
                            <label class="form-label">
                                Name
                                <input
                                    class="form-control"
                                    name="name"
                                    value="<?= $escape($current['name']); ?>"
                                    required
                                >
                            </label>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">
                                Version
                                <input
                                    class="form-control"
                                    name="version"
                                    value="<?= $escape($current['version']); ?>"
                                    required
                                >
                            </label>
                        </div>

                        <div class="col-12">
                            <label class="form-label">
                                Description
                                <textarea class="form-control" name="description"><?= $escape($current['description']); ?></textarea>
                            </label>
                        </div>

                        <div>
                            <button class="btn btn-outline-primary">Save metadata</button>
                        </div>
                    </form>
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="card h-100">
                            <div class="card-header fw-bold">Theme files</div>
                            <div class="list-group list-group-flush overflow-auto" style="max-height: 30rem;">
                                <?php foreach ($tree as $file): ?>
                                    <?php if ($file['directory']): ?>
                                        <span class="list-group-item">Directory: <?= $escape($file['path']); ?></span>
                                    <?php else: ?>
                                        <a
                                            class="list-group-item list-group-item-action"
                                            href="/admin/theme_builder?project=<?= rawurlencode($selected); ?>&amp;path=<?= rawurlencode($file['path']); ?>"
                                        ><?= $escape($file['path']); ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="card-body border-top">
                                <form method="post" class="mb-2">
                                    <?= $csrf_field; ?>
                                    <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                    <input type="hidden" name="action" value="create_file">
                                    <div class="input-group">
                                        <input class="form-control" name="path" placeholder="assets/css/extra.css">
                                        <button class="btn btn-outline-secondary">New file</button>
                                    </div>
                                </form>

                                <form method="post">
                                    <?= $csrf_field; ?>
                                    <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                    <input type="hidden" name="action" value="create_directory">
                                    <div class="input-group">
                                        <input class="form-control" name="path" placeholder="assets/fonts">
                                        <button class="btn btn-outline-secondary">New folder</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card h-100">
                            <div class="card-header fw-bold">
                                Editor<?= $path ? ': ' . $escape($path) : ''; ?>
                            </div>
                            <div class="card-body">
                                <?php if ($content !== null): ?>
                                    <form method="post">
                                        <?= $csrf_field; ?>
                                        <input type="hidden" name="action" value="save_file">
                                        <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                        <input type="hidden" name="path" value="<?= $escape($path); ?>">
                                        <textarea class="form-control font-monospace mb-3" rows="20" name="content"><?= $escape($content); ?></textarea>
                                        <button class="btn btn-primary">Save</button>
                                    </form>

                                    <div class="row g-2 mt-2">
                                        <div class="col-8">
                                            <form method="post">
                                                <?= $csrf_field; ?>
                                                <input type="hidden" name="action" value="rename_path">
                                                <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                                <input type="hidden" name="path" value="<?= $escape($path); ?>">
                                                <div class="input-group">
                                                    <input class="form-control" name="new_path" value="<?= $escape($path); ?>">
                                                    <button class="btn btn-outline-secondary">Rename</button>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="col-4">
                                            <form method="post" onsubmit="return confirm('Delete path?');">
                                                <?= $csrf_field; ?>
                                                <input type="hidden" name="action" value="delete_path">
                                                <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                                <input type="hidden" name="path" value="<?= $escape($path); ?>">
                                                <button class="btn btn-outline-danger w-100">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">
                                        Choose a text file. Binary assets stay in the project but are not opened in the text editor.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-lg-5">
                        <div class="card h-100">
                            <div class="card-header fw-bold">Validation</div>
                            <div class="card-body">
                                <?php if ($validation['valid']): ?>
                                    <p class="text-success fw-bold">Theme project valid.</p>
                                <?php else: ?>
                                    <ul class="text-danger">
                                        <?php foreach ($validation['errors'] as $validationError): ?>
                                            <li><?= $escape($validationError); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card h-100">
                            <div class="card-header fw-bold">Artifacts</div>
                            <div class="card-body">
                                <form method="post" class="mb-3">
                                    <?= $csrf_field; ?>
                                    <input type="hidden" name="action" value="build_release">
                                    <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                                    <button class="btn btn-success" <?= !$validation['valid'] ? 'disabled' : ''; ?>>
                                        Build unsigned theme release
                                    </button>
                                </form>

                                <ul class="list-group">
                                    <?php foreach ($artifacts as $artifact): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <span><?= $escape($artifact['name']); ?></span>
                                            <small><?= number_format($artifact['size']); ?> bytes</small>
                                        </li>
                                    <?php endforeach; ?>

                                    <?php if (!$artifacts): ?>
                                        <li class="list-group-item text-muted">No artifacts.</li>
                                    <?php endif; ?>
                                </ul>

                                <?php if ($certification['signing']): ?>
                                    <form method="post" enctype="multipart/form-data" class="mt-3">
                                        <?= $csrf_field; ?>
                                        <input type="hidden" name="action" value="sign_release">
                                        <input type="hidden" name="project" value="<?= $escape($selected); ?>">

                                        <label class="form-label">
                                            ZIP name
                                            <input class="form-control" name="artifact" required>
                                        </label>

                                        <label class="form-label">
                                            ChAoS-issued key
                                            <input type="file" class="form-control" name="private_key" required>
                                        </label>

                                        <button class="btn btn-outline-success">Sign once</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <form
                    method="post"
                    class="mt-4"
                    onsubmit="return confirm('Permanently delete live theme project?');"
                >
                    <?= $csrf_field; ?>
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="project" value="<?= $escape($selected); ?>">
                    <button class="btn btn-danger">Delete theme project</button>
                </form>
            <?php else: ?>
                <div class="card">
                    <div class="card-body py-5 text-center text-muted">
                        Create or choose a theme project.
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php /* [End AI:GPT-5.6 Sol] */ ?>
